<?php

namespace App\Services\Capacitacion;

use App\Models\AgenteWorkspace;
use App\Models\CapacitacionAsignacion;
use App\Models\CapacitacionConstancia;
use App\Models\CapacitacionCurso;
use App\Models\CapacitacionExamen;
use App\Models\CapacitacionIntentoExamen;
use App\Models\CapacitacionLeccion;
use App\Models\CapacitacionProgresoLeccion;
use App\Models\CapacitacionRespuestaIntento;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Authorization\WorkspaceAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CapacitacionService
{
    public function __construct(
        private WorkspaceAuthorizationService $authorization,
        private CapacitacionCertificatePdfService $pdfService,
    ) {}

    /**
     * Determina si el usuario autenticado puede administrar la academia de capacitaciones del workspace.
     *
     * Regla estricta:
     * - NO utiliza `agentes.manage` para conceder permisos administrativos de Capacitaciones.
     * - Autoriza al propietario del workspace (`owner_id`), al rol Administrador del sistema (`slug === 'admin'`),
     *   o a un rol que tenga asignado explícitamente el permiso `'capacitaciones.manage'`.
     */
    public function canManageCapacitaciones(User $user, Workspace $workspace): bool
    {
        $membership = $this->authorization->membership($user, (int) $workspace->id);
        if (! $membership) {
            return false;
        }

        if ((int) $workspace->owner_id === (int) $user->id) {
            return true;
        }

        $role = $membership->role;
        if (! $role) {
            return false;
        }

        if ($role->is_system && $role->slug === 'admin') {
            return true;
        }

        return $role->hasPermission('capacitaciones.manage');
    }

    /**
     * Exige permiso administrativo de Capacitaciones en el workspace activo o aborta con 403.
     */
    public function requireAdmin(User $user, Workspace $workspace): void
    {
        if (! $this->canManageCapacitaciones($user, $workspace)) {
            throw new HttpResponseException(response()->json([
                'message' => 'No tienes permiso para administrar las capacitaciones de esta promotoría.',
                'code' => 'capacitaciones_forbidden',
            ], 403));
        }
    }

    /**
     * Verifica que el usuario autenticado siga siendo un miembro activo del workspace
     * y que, si está vinculado a una ficha de agente en ese workspace, no se encuentre inactivo.
     */
    public function ensureActiveLearnerAccess(User $user, int $workspaceId, ?CapacitacionAsignacion $asignacion = null): void
    {
        $membership = $this->authorization->membership($user, $workspaceId);
        if (! $membership) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ya no perteneces a esta promotoría.',
                'code' => 'workspace_membership_revoked',
            ], 403));
        }

        if ($asignacion) {
            if ((int) $asignacion->workspace_id !== $workspaceId || (int) $asignacion->user_id !== (int) $user->id) {
                throw new HttpResponseException(response()->json([
                    'message' => 'No tienes acceso a esta asignación de capacitación.',
                    'code' => 'assignment_forbidden',
                ], 403));
            }

            if ($asignacion->agente_workspace_id) {
                $agenteWs = AgenteWorkspace::query()
                    ->where('id', $asignacion->agente_workspace_id)
                    ->where('workspace_id', $workspaceId)
                    ->first();

                if ($agenteWs && strtolower((string) $agenteWs->status) === 'inactivo') {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Tu perfil de agente se encuentra inactivo en esta promotoría.',
                        'code' => 'agent_inactive',
                    ], 403));
                }
            }
        }
    }

    /**
     * Devuelve la lista verificada de miembros del workspace elegibles para recibir asignaciones de cursos,
     * enlazando de forma no ambigua su ficha comercial en `agente_workspaces` cuando existe coincidencia única.
     *
     * @return list<array<string, mixed>>
     */
    public function getEligibleWorkspaceLearners(int $workspaceId): array
    {
        $memberships = WorkspaceUser::query()
            ->with(['user:id,name,email', 'role:id,nombre,slug,is_system'])
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get();

        $agenteWorkspaces = AgenteWorkspace::query()
            ->with('agente:id,cedula,nombre,apellido,email,telefono,activo')
            ->where('workspace_id', $workspaceId)
            ->get();

        // Agrupar fichas de agente por email normalizado para detectar y descartar ambigüedades
        $agentesByEmail = [];
        foreach ($agenteWorkspaces as $aw) {
            $email = strtolower(trim((string) ($aw->agente?->email ?? '')));
            if ($email !== '') {
                $agentesByEmail[$email][] = $aw;
            }
        }

        $result = [];
        foreach ($memberships as $m) {
            if (! $m->user) {
                continue;
            }

            $userEmail = strtolower(trim((string) $m->user->email));
            $matchedAgenteWs = null;

            // Solo vincular automáticamente con agente_workspaces si existe exactamente 1 coincidencia sin ambigüedad
            if ($userEmail !== '' && isset($agentesByEmail[$userEmail]) && count($agentesByEmail[$userEmail]) === 1) {
                $matchedAgenteWs = $agentesByEmail[$userEmail][0];
            }

            if ($matchedAgenteWs && strtolower((string) $matchedAgenteWs->status) === 'inactivo') {
                continue;
            }

            $nombreCompleto = $matchedAgenteWs && $matchedAgenteWs->agente
                ? trim($matchedAgenteWs->agente->nombre . ' ' . ($matchedAgenteWs->agente->apellido ?? ''))
                : (string) $m->user->name;

            $result[] = [
                'user_id' => (int) $m->user->id,
                'agente_workspace_id' => $matchedAgenteWs ? (int) $matchedAgenteWs->id : null,
                'nombre' => $nombreCompleto !== '' ? $nombreCompleto : (string) $m->user->name,
                'email' => (string) $m->user->email,
                'cedula' => $matchedAgenteWs?->agente?->cedula,
                'rol' => $m->role?->nombre ?? 'Miembro',
                'rol_slug' => $m->role?->slug,
                'status' => $matchedAgenteWs ? (string) $matchedAgenteWs->status : 'activo',
            ];
        }

        return $result;
    }

    /**
     * Asigna un curso publicado a uno o varios usuarios verificados del workspace,
     * evitando duplicados y asociando el progreso al `user_id` estable.
     *
     * @param  list<int>  $userIds
     * @return array{created: int, skipped: int, asignaciones: list<CapacitacionAsignacion>}
     */
    public function assignCourseToUsers(
        Workspace $workspace,
        CapacitacionCurso $curso,
        array $userIds,
        ?string $fechaLimite,
        User $assignedBy
    ): array {
        $eligibleMap = collect($this->getEligibleWorkspaceLearners((int) $workspace->id))
            ->keyBy('user_id');

        $uniqueUserIds = array_values(array_unique(array_map('intval', $userIds)));
        $created = 0;
        $skipped = 0;
        $asignaciones = [];

        DB::transaction(function () use (
            $workspace,
            $curso,
            $uniqueUserIds,
            $eligibleMap,
            $fechaLimite,
            $assignedBy,
            &$created,
            &$skipped,
            &$asignaciones
        ) {
            foreach ($uniqueUserIds as $targetUserId) {
                if (! $eligibleMap->has($targetUserId)) {
                    continue;
                }

                $learner = $eligibleMap->get($targetUserId);

                $existing = CapacitacionAsignacion::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('curso_id', $curso->id)
                    ->where('user_id', $targetUserId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($fechaLimite !== null && $existing->estado !== CapacitacionAsignacion::ESTADO_COMPLETADO) {
                        $existing->update(['fecha_limite' => $fechaLimite]);
                    }
                    $skipped++;
                    $asignaciones[] = $existing;
                    continue;
                }

                $asignacion = CapacitacionAsignacion::create([
                    'workspace_id' => $workspace->id,
                    'curso_id' => $curso->id,
                    'user_id' => $targetUserId,
                    'agente_workspace_id' => $learner['agente_workspace_id'],
                    'assigned_by' => $assignedBy->id,
                    'destinatario_nombre' => $learner['nombre'],
                    'destinatario_email' => $learner['email'],
                    'estado' => CapacitacionAsignacion::ESTADO_PENDIENTE,
                    'porcentaje_avance' => 0.00,
                    'aprobado' => false,
                    'fecha_asignacion' => Carbon::now(),
                    'fecha_limite' => $fechaLimite,
                ]);

                $created++;
                $asignaciones[] = $asignacion;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'asignaciones' => $asignaciones,
        ];
    }

    /**
     * Registra el progreso o finalización de una lección para una asignación verificada
     * y recalcula en el servidor el porcentaje real de avance del curso.
     *
     * @return array<string, mixed>
     */
    public function recordLessonProgress(
        CapacitacionAsignacion $asignacion,
        CapacitacionLeccion $leccion,
        int $segundosVistos = 0,
        int $duracionTotalSegundos = 0,
        bool $marcarCompletada = false
    ): array {
        if (
            (int) $asignacion->workspace_id !== (int) $leccion->workspace_id
            || (int) $asignacion->curso_id !== (int) $leccion->curso_id
        ) {
            throw new HttpResponseException(response()->json([
                'message' => 'La lección no corresponde al curso asignado.',
            ], 422));
        }

        $curso = $asignacion->curso()->firstOrFail();
        $minVideoPct = max(1, min(100, (int) ($curso->porcentaje_minimo_video ?: 85)));

        return DB::transaction(function () use (
            $asignacion,
            $leccion,
            $curso,
            $segundosVistos,
            $duracionTotalSegundos,
            $marcarCompletada,
            $minVideoPct
        ) {
            $lockedAsignacion = CapacitacionAsignacion::query()
                ->where('id', $asignacion->id)
                ->lockForUpdate()
                ->firstOrFail();

            $progreso = CapacitacionProgresoLeccion::query()
                ->where('asignacion_id', $lockedAsignacion->id)
                ->where('leccion_id', $leccion->id)
                ->lockForUpdate()
                ->first();

            $expectedDuration = max(
                (int) $leccion->duracion_segundos,
                (int) $duracionTotalSegundos,
                (int) ($progreso?->duracion_total_segundos ?? 0)
            );

            $accumulatedSeconds = max(
                (int) ($progreso?->segundos_vistos ?? 0),
                max(0, $segundosVistos)
            );

            if ($expectedDuration > 0 && $accumulatedSeconds > $expectedDuration) {
                $accumulatedSeconds = $expectedDuration;
            }

            $porcentajeVisto = $expectedDuration > 0
                ? round(min(100.0, ($accumulatedSeconds / $expectedDuration) * 100.0), 2)
                : ($marcarCompletada ? 100.0 : (float) ($progreso?->porcentaje_visto ?? 0.0));

            $yaCompletada = $progreso?->estado === CapacitacionProgresoLeccion::ESTADO_COMPLETADA;
            $puedeCompletar = $yaCompletada;

            if (! $puedeCompletar) {
                if ($leccion->tipo === 'video' && $expectedDuration > 0) {
                    if ($porcentajeVisto >= $minVideoPct) {
                        $puedeCompletar = true;
                    } elseif ($marcarCompletada) {
                        throw new HttpResponseException(response()->json([
                            'message' => sprintf(
                                'Para completar esta lección en video debes reproducir al menos el %d%% de su duración (avance actual: %s%%).',
                                $minVideoPct,
                                number_format($porcentajeVisto, 0)
                            ),
                            'code' => 'video_threshold_not_met',
                            'porcentaje_visto' => $porcentajeVisto,
                            'porcentaje_requerido' => $minVideoPct,
                        ], 422));
                    }
                } elseif ($marcarCompletada) {
                    $puedeCompletar = true;
                    $porcentajeVisto = 100.0;
                }
            }

            $now = Carbon::now();
            $nuevoEstado = $puedeCompletar
                ? CapacitacionProgresoLeccion::ESTADO_COMPLETADA
                : CapacitacionProgresoLeccion::ESTADO_EN_PROGRESO;

            $progreso = CapacitacionProgresoLeccion::updateOrCreate(
                [
                    'asignacion_id' => $lockedAsignacion->id,
                    'leccion_id' => $leccion->id,
                ],
                [
                    'workspace_id' => $lockedAsignacion->workspace_id,
                    'curso_id' => $lockedAsignacion->curso_id,
                    'leccion_titulo_snapshot' => $leccion->titulo,
                    'estado' => $nuevoEstado,
                    'segundos_vistos' => $accumulatedSeconds,
                    'duracion_total_segundos' => $expectedDuration,
                    'porcentaje_visto' => $puedeCompletar ? max($porcentajeVisto, 100.0) : $porcentajeVisto,
                    'completada_at' => $puedeCompletar ? ($progreso?->completada_at ?? $now) : null,
                    'ultimo_acceso_at' => $now,
                ]
            );

            $lockedAsignacion->ultima_leccion_id = $leccion->id;
            if (! $lockedAsignacion->fecha_inicio) {
                $lockedAsignacion->fecha_inicio = $now;
            }
            $lockedAsignacion->save();

            $updatedAsignacion = $this->recalculateAssignmentProgress($lockedAsignacion, $curso);
            $constancia = $this->issueCertificateIfEligible($updatedAsignacion, $curso);

            return [
                'progreso_leccion' => $progreso,
                'asignacion' => $updatedAsignacion->fresh(['constancia']),
                'constancia_emitida' => $constancia,
            ];
        });
    }

    /**
     * Califica un examen en el servidor de forma transaccional, validando límite de intentos,
     * pertenencia al workspace y emitiendo la constancia automáticamente si cumple todos los requisitos.
     *
     * @param  array<int|string, int>  $respuestasMapa  Mapa [pregunta_id => opcion_id]
     * @return array<string, mixed>
     */
    public function submitExamAttempt(
        CapacitacionAsignacion $asignacion,
        CapacitacionExamen $examen,
        User $user,
        array $respuestasMapa
    ): array {
        if (
            (int) $asignacion->workspace_id !== (int) $examen->workspace_id
            || (int) $asignacion->curso_id !== (int) $examen->curso_id
            || (int) $asignacion->user_id !== (int) $user->id
        ) {
            throw new HttpResponseException(response()->json([
                'message' => 'El examen no corresponde a tu asignación activa.',
            ], 403));
        }

        if (! $examen->activo) {
            throw new HttpResponseException(response()->json([
                'message' => 'Esta evaluación no se encuentra activa.',
            ], 422));
        }

        $examen->load(['preguntas.opciones']);
        if ($examen->preguntas->isEmpty()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Este examen aún no tiene preguntas configuradas.',
            ], 422));
        }

        $curso = $asignacion->curso()->firstOrFail();

        return DB::transaction(function () use ($asignacion, $examen, $curso, $user, $respuestasMapa) {
            $lockedAsignacion = CapacitacionAsignacion::query()
                ->where('id', $asignacion->id)
                ->lockForUpdate()
                ->firstOrFail();

            $intentosPrevios = CapacitacionIntentoExamen::query()
                ->where('asignacion_id', $lockedAsignacion->id)
                ->where('examen_id', $examen->id)
                ->lockForUpdate()
                ->orderByDesc('numero_intento')
                ->get();

            $totalIntentos = $intentosPrevios->count();
            $maxIntentos = (int) $examen->max_intentos;

            if ($maxIntentos > 0 && $totalIntentos >= $maxIntentos) {
                throw new HttpResponseException(response()->json([
                    'message' => sprintf('Has alcanzado el límite máximo de %d intento(s) para esta evaluación.', $maxIntentos),
                    'code' => 'max_attempts_reached',
                ], 422));
            }

            $numeroIntento = $totalIntentos + 1;
            $puntajeMaximo = 0.0;
            $puntajeObtenido = 0.0;
            $detallesRespuestas = [];

            foreach ($examen->preguntas as $pregunta) {
                $pesoPregunta = max(0.01, (float) $pregunta->puntaje);
                $puntajeMaximo += $pesoPregunta;

                $opcionSeleccionadaId = isset($respuestasMapa[$pregunta->id])
                    ? (int) $respuestasMapa[$pregunta->id]
                    : (isset($respuestasMapa[(string) $pregunta->id]) ? (int) $respuestasMapa[(string) $pregunta->id] : 0);

                $opcionElegida = $pregunta->opciones->firstWhere('id', $opcionSeleccionadaId);
                $esCorrecta = $opcionElegida ? (bool) $opcionElegida->es_correcta : false;
                $puntosPregunta = $esCorrecta ? $pesoPregunta : 0.0;
                $puntajeObtenido += $puntosPregunta;

                $detallesRespuestas[] = [
                    'workspace_id' => $lockedAsignacion->workspace_id,
                    'pregunta_id' => $pregunta->id,
                    'opcion_id' => $opcionElegida?->id,
                    'enunciado_snapshot' => $pregunta->enunciado,
                    'inciso_seleccionado_snapshot' => $opcionElegida?->inciso,
                    'opcion_texto_snapshot' => $opcionElegida?->texto,
                    'es_correcta' => $esCorrecta,
                    'puntaje_obtenido' => $puntosPregunta,
                ];
            }

            $calificacion = $puntajeMaximo > 0
                ? round(($puntajeObtenido / $puntajeMaximo) * 100.0, 2)
                : 0.0;
            $calificacionMinima = (float) $examen->calificacion_minima;
            $aprobado = $calificacion >= $calificacionMinima;
            $now = Carbon::now();

            $intento = CapacitacionIntentoExamen::create([
                'workspace_id' => $lockedAsignacion->workspace_id,
                'asignacion_id' => $lockedAsignacion->id,
                'curso_id' => $lockedAsignacion->curso_id,
                'examen_id' => $examen->id,
                'user_id' => $user->id,
                'examen_titulo_snapshot' => $examen->titulo,
                'numero_intento' => $numeroIntento,
                'estado' => 'enviado',
                'puntaje_obtenido' => $puntajeObtenido,
                'puntaje_maximo' => $puntajeMaximo,
                'calificacion' => $calificacion,
                'calificacion_minima_snapshot' => $calificacionMinima,
                'aprobado' => $aprobado,
                'iniciado_at' => $now,
                'enviado_at' => $now,
            ]);

            foreach ($detallesRespuestas as $detalle) {
                CapacitacionRespuestaIntento::create(array_merge($detalle, [
                    'intento_id' => $intento->id,
                ]));
            }

            if (! $lockedAsignacion->fecha_inicio) {
                $lockedAsignacion->fecha_inicio = $now;
                $lockedAsignacion->save();
            }

            // Si la evaluación está vinculada a una lección tipo evaluación y fue aprobada, marcar también la lección completada
            if ($aprobado && $examen->leccion_id) {
                CapacitacionProgresoLeccion::updateOrCreate(
                    [
                        'asignacion_id' => $lockedAsignacion->id,
                        'leccion_id' => $examen->leccion_id,
                    ],
                    [
                        'workspace_id' => $lockedAsignacion->workspace_id,
                        'curso_id' => $lockedAsignacion->curso_id,
                        'leccion_titulo_snapshot' => $examen->titulo,
                        'estado' => CapacitacionProgresoLeccion::ESTADO_COMPLETADA,
                        'porcentaje_visto' => 100.0,
                        'completada_at' => $now,
                        'ultimo_acceso_at' => $now,
                    ]
                );
            }

            $updatedAsignacion = $this->recalculateAssignmentProgress($lockedAsignacion, $curso);
            $constancia = $this->issueCertificateIfEligible($updatedAsignacion, $curso);

            return [
                'intento' => $intento->load('respuestas'),
                'intentos_restantes' => $maxIntentos > 0 ? max(0, $maxIntentos - $numeroIntento) : null,
                'asignacion' => $updatedAsignacion->fresh(['constancia']),
                'constancia_emitida' => $constancia,
            ];
        });
    }

    /**
     * Recalcula el porcentaje real de avance y estado de una asignación exclusivamente con datos del backend.
     */
    public function recalculateAssignmentProgress(
        CapacitacionAsignacion $asignacion,
        ?CapacitacionCurso $curso = null
    ): CapacitacionAsignacion {
        $curso = $curso ?: $asignacion->curso()->firstOrFail();

        $leccionesObligatoriasIds = CapacitacionLeccion::query()
            ->where('curso_id', $curso->id)
            ->where('es_obligatoria', true)
            ->pluck('id')
            ->all();

        $examenesObligatoriosIds = $curso->requiere_examen_aprobado
            ? CapacitacionExamen::query()
                ->where('curso_id', $curso->id)
                ->where('activo', true)
                ->where('es_obligatorio', true)
                ->pluck('id')
                ->all()
            : [];

        $totalRequisitos = count($leccionesObligatoriasIds) + count($examenesObligatoriosIds);

        $leccionesCompletadasCount = empty($leccionesObligatoriasIds)
            ? 0
            : CapacitacionProgresoLeccion::query()
                ->where('asignacion_id', $asignacion->id)
                ->whereIn('leccion_id', $leccionesObligatoriasIds)
                ->where('estado', CapacitacionProgresoLeccion::ESTADO_COMPLETADA)
                ->count();

        $examenesAprobadosIds = empty($examenesObligatoriosIds)
            ? []
            : CapacitacionIntentoExamen::query()
                ->where('asignacion_id', $asignacion->id)
                ->whereIn('examen_id', $examenesObligatoriosIds)
                ->where('aprobado', true)
                ->distinct()
                ->pluck('examen_id')
                ->all();

        $examenesAprobadosCount = count($examenesAprobadosIds);
        $completados = $leccionesCompletadasCount + $examenesAprobadosCount;

        $porcentaje = $totalRequisitos > 0
            ? round(min(100.0, ($completados / $totalRequisitos) * 100.0), 2)
            : 0.0;

        // Calcular promedio de mejores calificaciones en los exámenes del curso
        $mejoresCalificaciones = CapacitacionIntentoExamen::query()
            ->where('asignacion_id', $asignacion->id)
            ->selectRaw('examen_id, MAX(calificacion) as max_calif')
            ->groupBy('examen_id')
            ->pluck('max_calif');

        $calificacionFinal = $mejoresCalificaciones->isNotEmpty()
            ? round((float) $mejoresCalificaciones->avg(), 2)
            : $asignacion->calificacion_final;

        $todasLeccionesCumplidas = count($leccionesObligatoriasIds) === 0
            || $leccionesCompletadasCount >= count($leccionesObligatoriasIds);
        $todosExamenesCumplidos = count($examenesObligatoriosIds) === 0
            || $examenesAprobadosCount >= count($examenesObligatoriosIds);

        // Para completar un curso debe existir al menos 1 lección o 1 examen obligatorio y haberse cumplido todos
        $cursoAcreditado = $totalRequisitos > 0 && $todasLeccionesCumplidas && $todosExamenesCumplidos;

        $tieneActividad = $completados > 0
            || $asignacion->fecha_inicio !== null
            || CapacitacionProgresoLeccion::query()->where('asignacion_id', $asignacion->id)->exists()
            || CapacitacionIntentoExamen::query()->where('asignacion_id', $asignacion->id)->exists();

        if ($cursoAcreditado) {
            $asignacion->estado = CapacitacionAsignacion::ESTADO_COMPLETADO;
            $asignacion->porcentaje_avance = 100.00;
            $asignacion->aprobado = true;
            $asignacion->fecha_completado = $asignacion->fecha_completado ?? Carbon::now();
        } elseif ($tieneActividad) {
            // Si ya había sido completado previamente, no degradar su estado histórico
            if ($asignacion->estado !== CapacitacionAsignacion::ESTADO_COMPLETADO) {
                $asignacion->estado = CapacitacionAsignacion::ESTADO_EN_PROGRESO;
                $asignacion->porcentaje_avance = $porcentaje;
            }
        } else {
            if ($asignacion->estado !== CapacitacionAsignacion::ESTADO_COMPLETADO) {
                $asignacion->estado = CapacitacionAsignacion::ESTADO_PENDIENTE;
                $asignacion->porcentaje_avance = 0.00;
            }
        }

        $asignacion->calificacion_final = $calificacionFinal;
        $asignacion->save();

        return $asignacion;
    }

    /**
     * Emite de manera idempotente la constancia en PDF cuando la asignación ha cumplido
     * todos los requisitos obligatorios del curso. Conserva un snapshot histórico inmutable.
     */
    public function issueCertificateIfEligible(
        CapacitacionAsignacion $asignacion,
        ?CapacitacionCurso $curso = null
    ): ?CapacitacionConstancia {
        if ($asignacion->estado !== CapacitacionAsignacion::ESTADO_COMPLETADO || ! $asignacion->aprobado) {
            return null;
        }

        $existing = CapacitacionConstancia::query()
            ->where('workspace_id', $asignacion->workspace_id)
            ->where('asignacion_id', $asignacion->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $curso = $curso ?: $asignacion->curso()->firstOrFail();
        $workspace = $asignacion->workspace()->firstOrFail();

        $folio = $this->generateUniqueFolio((int) $workspace->id, (int) $asignacion->id);
        $codigoVerificacion = strtoupper(Str::random(16));

        $minutos = (int) $curso->duracion_minutos;
        if ($minutos <= 0) {
            $minutos = (int) $curso->lecciones()->sum('duracion_minutos');
        }
        $duracionTexto = $minutos >= 60
            ? sprintf('%d h %d min', intdiv($minutos, 60), $minutos % 60)
            : ($minutos > 0 ? sprintf('%d min', $minutos) : 'Curso acreditado');

        $constancia = new CapacitacionConstancia([
            'workspace_id' => $workspace->id,
            'asignacion_id' => $asignacion->id,
            'curso_id' => $curso->id,
            'user_id' => $asignacion->user_id,
            'agente_workspace_id' => $asignacion->agente_workspace_id,
            'folio' => $folio,
            'codigo_verificacion' => $codigoVerificacion,
            'nombre_agente' => $asignacion->destinatario_nombre,
            'nombre_curso' => $curso->titulo,
            'nombre_promotoria' => $workspace->nombre,
            'duracion_texto' => $duracionTexto,
            'calificacion_obtenida' => $asignacion->calificacion_final,
            'responsable_nombre' => $curso->responsable_nombre ?: $workspace->nombre,
            'responsable_cargo' => $curso->responsable_cargo ?: 'Coordinación de Capacitación',
            'fecha_emision' => $asignacion->fecha_completado ?? Carbon::now(),
            'disk' => 'local',
            'pdf_path' => '',
        ]);

        $stored = $this->pdfService->generateAndStore($constancia, $curso);
        $constancia->disk = $stored['disk'];
        $constancia->pdf_path = $stored['path'];
        $constancia->sha256_hash = $stored['sha256'];
        $constancia->save();

        return $constancia;
    }

    private function generateUniqueFolio(int $workspaceId, int $asignacionId): string
    {
        do {
            $suffix = strtoupper(Str::random(4));
            $folio = sprintf(
                'GSEA-CAP-%s-W%d-%04d-%s',
                now()->format('Y'),
                $workspaceId,
                $asignacionId,
                $suffix
            );
        } while (CapacitacionConstancia::query()->where('folio', $folio)->exists());

        return $folio;
    }
}

