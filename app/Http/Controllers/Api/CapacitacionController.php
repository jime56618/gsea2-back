<?php

namespace App\Http\Controllers\Api;

use App\Models\CapacitacionAsignacion;
use App\Models\CapacitacionConstancia;
use App\Models\CapacitacionCurso;
use App\Models\CapacitacionExamen;
use App\Models\CapacitacionIntentoExamen;
use App\Models\CapacitacionLeccion;
use App\Models\CapacitacionModulo;
use App\Models\CapacitacionOpcion;
use App\Models\CapacitacionPregunta;
use App\Models\CapacitacionProgresoLeccion;
use App\Models\CapacitacionRecurso;
use App\Models\CapacitacionRespuestaIntento;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Capacitacion\CapacitacionCertificatePdfService;
use App\Services\Capacitacion\CapacitacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CapacitacionController extends BaseApiController
{
    public function __construct(
        private CapacitacionService $capacitaciones,
        private CapacitacionCertificatePdfService $pdfService,
    ) {}

    private function resolveActiveWorkspace(Request $request): Workspace
    {
        $workspaceId = $this->resolveWorkspaceId($request);

        return Workspace::query()->findOrFail($workspaceId);
    }

    // =========================================================================
    // SECCIÓN 1: ENDPOINTS ADMINISTRATIVOS (ACADEMIA DE LA PROMOTORÍA)
    // =========================================================================

    /**
     * Resumen ejecutivo de la academia para el propietario / administrador del workspace.
     */
    public function adminDashboard(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        $wsId = (int) $workspace->id;

        $cursosCounts = CapacitacionCurso::query()
            ->where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN estado = 'publicado' THEN 1 ELSE 0 END) as publicados")
            ->selectRaw("SUM(CASE WHEN estado = 'borrador' THEN 1 ELSE 0 END) as borradores")
            ->selectRaw("SUM(CASE WHEN estado = 'archivado' THEN 1 ELSE 0 END) as archivados")
            ->first();

        $asignacionesStats = CapacitacionAsignacion::query()
            ->where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) as total_asignaciones')
            ->selectRaw('COUNT(DISTINCT user_id) as agentes_asignados')
            ->selectRaw("SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes")
            ->selectRaw("SUM(CASE WHEN estado = 'en_progreso' THEN 1 ELSE 0 END) as en_progreso")
            ->selectRaw("SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados")
            ->selectRaw(' COALESCE(AVG(porcentaje_avance), 0) as promedio_avance')
            ->first();

        $examenesStats = CapacitacionIntentoExamen::query()
            ->where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) as total_intentos')
            ->selectRaw('SUM(CASE WHEN aprobado = 1 THEN 1 ELSE 0 END) as aprobados')
            ->selectRaw('SUM(CASE WHEN aprobado = 0 THEN 1 ELSE 0 END) as reprobados')
            ->selectRaw('COALESCE(AVG(calificacion), 0) as promedio_calificacion')
            ->first();

        $totalConstancias = CapacitacionConstancia::query()
            ->where('workspace_id', $wsId)
            ->count();

        // Resumen por agente (usuarios asignados o elegibles en el workspace)
        $learners = $this->capacitaciones->getEligibleWorkspaceLearners($wsId);
        $asignacionesPorUsuario = CapacitacionAsignacion::query()
            ->with(['curso:id,titulo,categoria,nivel,estado', 'constancia:id,asignacion_id,folio,fecha_emision'])
            ->where('workspace_id', $wsId)
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('user_id');

        $resumenAgentes = [];
        foreach ($learners as $learner) {
            $uid = (int) $learner['user_id'];
            $items = $asignacionesPorUsuario->get($uid, collect());

            $resumenAgentes[] = [
                'user_id' => $uid,
                'agente_workspace_id' => $learner['agente_workspace_id'],
                'nombre' => $learner['nombre'],
                'email' => $learner['email'],
                'cedula' => $learner['cedula'],
                'rol' => $learner['rol'],
                'cursos_asignados' => $items->count(),
                'pendientes' => $items->where('estado', CapacitacionAsignacion::ESTADO_PENDIENTE)->count(),
                'en_progreso' => $items->where('estado', CapacitacionAsignacion::ESTADO_EN_PROGRESO)->count(),
                'completados' => $items->where('estado', CapacitacionAsignacion::ESTADO_COMPLETADO)->count(),
                'promedio_avance' => $items->isNotEmpty() ? round((float) $items->avg('porcentaje_avance'), 1) : 0.0,
                'promedio_calificacion' => $items->whereNotNull('calificacion_final')->isNotEmpty()
                    ? round((float) $items->whereNotNull('calificacion_final')->avg('calificacion_final'), 1)
                    : null,
                'constancias_emitidas' => $items->filter(fn ($a) => $a->constancia !== null)->count(),
                'ultima_actividad' => $items->max('updated_at'),
            ];
        }

        // Resumen por curso
        $cursosResumen = CapacitacionCurso::query()
            ->where('workspace_id', $wsId)
            ->withCount(['modulos', 'lecciones', 'examenes', 'asignaciones', 'constancias'])
            ->withAvg('asignaciones as promedio_avance', 'porcentaje_avance')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (CapacitacionCurso $c) {
                $completadosCount = CapacitacionAsignacion::query()
                    ->where('curso_id', $c->id)
                    ->where('estado', CapacitacionAsignacion::ESTADO_COMPLETADO)
                    ->count();

                return [
                    'id' => $c->id,
                    'titulo' => $c->titulo,
                    'categoria' => $c->categoria,
                    'nivel' => $c->nivel,
                    'estado' => $c->estado,
                    'duracion_minutos' => $c->duracion_minutos,
                    'modulos_count' => $c->modulos_count,
                    'lecciones_count' => $c->lecciones_count,
                    'examenes_count' => $c->examenes_count,
                    'asignaciones_count' => $c->asignaciones_count,
                    'completados_count' => $completadosCount,
                    'constancias_count' => $c->constancias_count,
                    'promedio_avance' => round((float) ($c->promedio_avance ?? 0), 1),
                    'updated_at' => $c->updated_at,
                ];
            });

        return response()->json([
            'kpis' => [
                'total_cursos' => (int) ($cursosCounts->total ?? 0),
                'cursos_publicados' => (int) ($cursosCounts->publicados ?? 0),
                'cursos_borrador' => (int) ($cursosCounts->borradores ?? 0),
                'cursos_archivados' => (int) ($cursosCounts->archivados ?? 0),
                'total_asignaciones' => (int) ($asignacionesStats->total_asignaciones ?? 0),
                'agentes_asignados' => (int) ($asignacionesStats->agentes_asignados ?? 0),
                'cursos_pendientes' => (int) ($asignacionesStats->pendientes ?? 0),
                'cursos_en_progreso' => (int) ($asignacionesStats->en_progreso ?? 0),
                'cursos_completados' => (int) ($asignacionesStats->completados ?? 0),
                'promedio_avance_global' => round((float) ($asignacionesStats->promedio_avance ?? 0), 1),
                'total_intentos_examen' => (int) ($examenesStats->total_intentos ?? 0),
                'examenes_aprobados' => (int) ($examenesStats->aprobados ?? 0),
                'examenes_reprobados' => (int) ($examenesStats->reprobados ?? 0),
                'promedio_calificacion_examenes' => round((float) ($examenesStats->promedio_calificacion ?? 0), 1),
                'constancias_emitidas' => $totalConstancias,
            ],
            'resumen_agentes' => $resumenAgentes,
            'resumen_cursos' => $cursosResumen,
        ]);
    }

    /**
     * Lista de usuarios miembros del workspace elegibles para recibir asignaciones de capacitación.
     */
    public function eligibleLearners(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        return response()->json([
            'learners' => $this->capacitaciones->getEligibleWorkspaceLearners((int) $workspace->id),
        ]);
    }

    /**
     * Expediente académico completo de un usuario/agente específico dentro del workspace.
     */
    public function agentExpediente(Request $request, int $userId): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);
        $wsId = (int) $workspace->id;

        $learners = collect($this->capacitaciones->getEligibleWorkspaceLearners($wsId))->keyBy('user_id');
        $learnerInfo = $learners->get($userId);

        $targetUser = User::query()->select(['id', 'name', 'email'])->findOrFail($userId);

        $asignaciones = CapacitacionAsignacion::query()
            ->with([
                'curso:id,workspace_id,titulo,subtitulo,categoria,nivel,duracion_minutos,estado,requiere_examen_aprobado',
                'progresoLecciones:id,asignacion_id,leccion_id,leccion_titulo_snapshot,estado,segundos_vistos,duracion_total_segundos,porcentaje_visto,completada_at',
                'intentosExamen:id,asignacion_id,examen_id,examen_titulo_snapshot,numero_intento,calificacion,calificacion_minima_snapshot,aprobado,enviado_at',
                'constancia:id,asignacion_id,folio,codigo_verificacion,nombre_agente,nombre_curso,duracion_texto,calificacion_obtenida,fecha_emision',
            ])
            ->where('workspace_id', $wsId)
            ->where('user_id', $userId)
            ->orderByDesc('fecha_asignacion')
            ->get();

        if (! $learnerInfo && $asignaciones->isEmpty()) {
            return response()->json([
                'message' => 'El usuario indicado no pertenece ni tiene historial académico en esta promotoría.',
            ], 404);
        }

        return response()->json([
            'agente' => $learnerInfo ?: [
                'user_id' => $targetUser->id,
                'agente_workspace_id' => null,
                'nombre' => $targetUser->name,
                'email' => $targetUser->email,
                'cedula' => null,
                'rol' => 'Histórico',
                'status' => 'inactivo',
            ],
            'asignaciones' => $asignaciones,
        ]);
    }

    /**
     * Listado administrativo de todos los cursos de la promotoría.
     */
    public function adminIndexCursos(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $query = CapacitacionCurso::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['modulos', 'lecciones', 'examenes', 'asignaciones', 'constancias'])
            ->withAvg('asignaciones as promedio_avance', 'porcentaje_avance')
            ->orderByDesc('updated_at');

        if ($request->filled('estado') && in_array($request->string('estado')->toString(), ['borrador', 'publicado', 'archivado'], true)) {
            $query->where('estado', $request->string('estado')->toString());
        }

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->string('categoria')->toString());
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->string('search')->toString()) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('titulo', 'like', $term)
                    ->orWhere('subtitulo', 'like', $term)
                    ->orWhere('categoria', 'like', $term);
            });
        }

        return response()->json([
            'cursos' => $query->get(),
        ]);
    }

    /**
     * Crea un nuevo curso en el workspace activo.
     */
    public function adminStoreCurso(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:190'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'objetivos' => ['nullable', 'array'],
            'objetivos.*' => ['nullable', 'string', 'max:500'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'nivel' => ['nullable', Rule::in(['basico', 'intermedio', 'avanzado'])],
            'duracion_minutos' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'requiere_examen_aprobado' => ['nullable', 'boolean'],
            'porcentaje_minimo_video' => ['nullable', 'integer', 'min:10', 'max:100'],
            'responsable_nombre' => ['nullable', 'string', 'max:150'],
            'responsable_cargo' => ['nullable', 'string', 'max:150'],
            'portada' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $portadaPath = null;
        if ($request->hasFile('portada')) {
            $portadaPath = $request->file('portada')->store(
                sprintf('capacitaciones/workspace_%d/portadas', $workspace->id),
                'local'
            );
        }

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store(
                sprintf('capacitaciones/workspace_%d/logos', $workspace->id),
                'local'
            );
        }

        $objetivos = isset($validated['objetivos'])
            ? array_values(array_filter(array_map('trim', $validated['objetivos']), fn ($v) => $v !== ''))
            : [];

        $curso = CapacitacionCurso::create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'titulo' => trim($validated['titulo']),
            'subtitulo' => $validated['subtitulo'] ?? null,
            'descripcion' => $validated['descripcion'] ?? null,
            'objetivos_json' => $objetivos,
            'categoria' => $validated['categoria'] ?: 'General',
            'nivel' => $validated['nivel'] ?? 'basico',
            'duracion_minutos' => (int) ($validated['duracion_minutos'] ?? 0),
            'portada_disk' => 'local',
            'portada_path' => $portadaPath,
            'logo_constancia_path' => $logoPath,
            'responsable_nombre' => $validated['responsable_nombre'] ?: $user->name,
            'responsable_cargo' => $validated['responsable_cargo'] ?: 'Coordinación de Capacitación',
            'estado' => CapacitacionCurso::ESTADO_BORRADOR,
            'requiere_examen_aprobado' => isset($validated['requiere_examen_aprobado'])
                ? (bool) $validated['requiere_examen_aprobado']
                : false,
            'porcentaje_minimo_video' => (int) ($validated['porcentaje_minimo_video'] ?? 85),
        ]);

        return response()->json([
            'message' => 'Curso creado correctamente en borrador.',
            'curso' => $this->loadFullAdminCurso($curso),
        ], 201);
    }

    /**
     * Detalle completo de un curso para el constructor estilo Udemy (incluye respuestas correctas de exámenes).
     */
    public function adminShowCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        return response()->json([
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    /**
     * Actualiza la información general, portada o configuración de constancia de un curso.
     */
    public function adminUpdateCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        $validated = $request->validate([
            'titulo' => ['sometimes', 'required', 'string', 'max:190'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'objetivos' => ['nullable', 'array'],
            'objetivos.*' => ['nullable', 'string', 'max:500'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'nivel' => ['nullable', Rule::in(['basico', 'intermedio', 'avanzado'])],
            'duracion_minutos' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'requiere_examen_aprobado' => ['nullable', 'boolean'],
            'porcentaje_minimo_video' => ['nullable', 'integer', 'min:10', 'max:100'],
            'responsable_nombre' => ['nullable', 'string', 'max:150'],
            'responsable_cargo' => ['nullable', 'string', 'max:150'],
            'portada' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($request->hasFile('portada')) {
            if ($curso->portada_path && Storage::disk($curso->portada_disk ?: 'local')->exists($curso->portada_path)) {
                Storage::disk($curso->portada_disk ?: 'local')->delete($curso->portada_path);
            }
            $curso->portada_path = $request->file('portada')->store(
                sprintf('capacitaciones/workspace_%d/portadas', $workspace->id),
                'local'
            );
            $curso->portada_disk = 'local';
        }

        if ($request->hasFile('logo')) {
            if ($curso->logo_constancia_path && Storage::disk('local')->exists($curso->logo_constancia_path)) {
                Storage::disk('local')->delete($curso->logo_constancia_path);
            }
            $curso->logo_constancia_path = $request->file('logo')->store(
                sprintf('capacitaciones/workspace_%d/logos', $workspace->id),
                'local'
            );
        }

        if (array_key_exists('titulo', $validated)) {
            $curso->titulo = trim($validated['titulo']);
        }
        if (array_key_exists('subtitulo', $validated)) {
            $curso->subtitulo = $validated['subtitulo'];
        }
        if (array_key_exists('descripcion', $validated)) {
            $curso->descripcion = $validated['descripcion'];
        }
        if (array_key_exists('objetivos', $validated)) {
            $curso->objetivos_json = is_array($validated['objetivos'])
                ? array_values(array_filter(array_map('trim', $validated['objetivos']), fn ($v) => $v !== ''))
                : [];
        }
        if (array_key_exists('categoria', $validated) && $validated['categoria']) {
            $curso->categoria = $validated['categoria'];
        }
        if (array_key_exists('nivel', $validated) && $validated['nivel']) {
            $curso->nivel = $validated['nivel'];
        }
        if (array_key_exists('duracion_minutos', $validated)) {
            $curso->duracion_minutos = (int) $validated['duracion_minutos'];
        }
        if (array_key_exists('requiere_examen_aprobado', $validated)) {
            $curso->requiere_examen_aprobado = (bool) $validated['requiere_examen_aprobado'];
        }
        if (array_key_exists('porcentaje_minimo_video', $validated) && $validated['porcentaje_minimo_video'] !== null) {
            $curso->porcentaje_minimo_video = (int) $validated['porcentaje_minimo_video'];
        }
        if (array_key_exists('responsable_nombre', $validated)) {
            $curso->responsable_nombre = $validated['responsable_nombre'];
        }
        if (array_key_exists('responsable_cargo', $validated)) {
            $curso->responsable_cargo = $validated['responsable_cargo'];
        }

        $curso->updated_by = $user->id;
        $curso->save();

        return response()->json([
            'message' => 'Curso actualizado correctamente.',
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    /**
     * Cambia el estado de un curso (`borrador`, `publicado`, `archivado`).
     * Archivar conserva intactos asignaciones, intentos, calificaciones y constancias.
     */
    public function adminUpdateEstadoCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        $validated = $request->validate([
            'estado' => ['required', Rule::in([
                CapacitacionCurso::ESTADO_BORRADOR,
                CapacitacionCurso::ESTADO_PUBLICADO,
                CapacitacionCurso::ESTADO_ARCHIVADO,
            ])],
        ]);

        $nuevoEstado = $validated['estado'];

        if ($nuevoEstado === CapacitacionCurso::ESTADO_PUBLICADO) {
            $leccionesCount = $curso->lecciones()->count();
            if ($leccionesCount === 0) {
                return response()->json([
                    'message' => 'Para publicar un curso debes agregar al menos un módulo y una lección.',
                ], 422);
            }

            if ($curso->requiere_examen_aprobado) {
                $examenValido = $curso->examenes()
                    ->where('activo', true)
                    ->whereHas('preguntas')
                    ->exists();

                if (! $examenValido) {
                    return response()->json([
                        'message' => 'El curso exige aprobar un examen, pero aún no tiene una evaluación activa con preguntas.',
                    ], 422);
                }
            }

            $curso->publicado_at = $curso->publicado_at ?? now();
            $curso->archivado_at = null;
        } elseif ($nuevoEstado === CapacitacionCurso::ESTADO_ARCHIVADO) {
            $curso->archivado_at = now();
        }

        $curso->estado = $nuevoEstado;
        $curso->updated_by = $user->id;
        $curso->save();

        return response()->json([
            'message' => sprintf('El estado del curso cambió a «%s».', $nuevoEstado),
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    /**
     * Duplica la estructura de un curso (módulos, lecciones, recursos y exámenes) como nuevo borrador.
     */
    public function adminDuplicateCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        $curso->load(['modulos.lecciones.recursos', 'examenes.preguntas.opciones']);

        $nuevoCurso = DB::transaction(function () use ($curso, $workspace, $user) {
            $copia = CapacitacionCurso::create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'titulo' => $curso->titulo . ' (Copia)',
                'subtitulo' => $curso->subtitulo,
                'descripcion' => $curso->descripcion,
                'objetivos_json' => $curso->objetivos_json,
                'categoria' => $curso->categoria,
                'nivel' => $curso->nivel,
                'duracion_minutos' => $curso->duracion_minutos,
                'portada_disk' => $curso->portada_disk,
                'portada_path' => null,
                'logo_constancia_path' => null,
                'responsable_nombre' => $curso->responsable_nombre,
                'responsable_cargo' => $curso->responsable_cargo,
                'estado' => CapacitacionCurso::ESTADO_BORRADOR,
                'requiere_examen_aprobado' => $curso->requiere_examen_aprobado,
                'porcentaje_minimo_video' => $curso->porcentaje_minimo_video,
            ]);

            $moduloMap = [];
            $leccionMap = [];

            foreach ($curso->modulos as $modulo) {
                $nuevoModulo = CapacitacionModulo::create([
                    'workspace_id' => $workspace->id,
                    'curso_id' => $copia->id,
                    'titulo' => $modulo->titulo,
                    'descripcion' => $modulo->descripcion,
                    'orden' => $modulo->orden,
                ]);
                $moduloMap[$modulo->id] = $nuevoModulo->id;

                foreach ($modulo->lecciones as $leccion) {
                    $nuevaLeccion = CapacitacionLeccion::create([
                        'workspace_id' => $workspace->id,
                        'curso_id' => $copia->id,
                        'modulo_id' => $nuevoModulo->id,
                        'titulo' => $leccion->titulo,
                        'resumen' => $leccion->resumen,
                        'tipo' => $leccion->tipo,
                        'contenido_html' => $leccion->contenido_html,
                        'video_proveedor' => $leccion->video_proveedor === 'local' ? null : $leccion->video_proveedor,
                        'video_url' => $leccion->video_proveedor === 'local' ? null : $leccion->video_url,
                        'duracion_minutos' => $leccion->duracion_minutos,
                        'duracion_segundos' => $leccion->duracion_segundos,
                        'orden' => $leccion->orden,
                        'es_obligatoria' => $leccion->es_obligatoria,
                    ]);
                    $leccionMap[$leccion->id] = $nuevaLeccion->id;

                    foreach ($leccion->recursos as $recurso) {
                        if ($recurso->tipo === 'enlace' && $recurso->url_externa) {
                            CapacitacionRecurso::create([
                                'workspace_id' => $workspace->id,
                                'curso_id' => $copia->id,
                                'leccion_id' => $nuevaLeccion->id,
                                'tipo' => 'enlace',
                                'titulo' => $recurso->titulo,
                                'url_externa' => $recurso->url_externa,
                                'es_principal' => $recurso->es_principal,
                                'es_descargable' => $recurso->es_descargable,
                                'orden' => $recurso->orden,
                            ]);
                        }
                    }
                }
            }

            foreach ($curso->examenes as $examen) {
                $nuevoExamen = CapacitacionExamen::create([
                    'workspace_id' => $workspace->id,
                    'curso_id' => $copia->id,
                    'modulo_id' => $examen->modulo_id ? ($moduloMap[$examen->modulo_id] ?? null) : null,
                    'leccion_id' => $examen->leccion_id ? ($leccionMap[$examen->leccion_id] ?? null) : null,
                    'titulo' => $examen->titulo,
                    'instrucciones' => $examen->instrucciones,
                    'calificacion_minima' => $examen->calificacion_minima,
                    'max_intentos' => $examen->max_intentos,
                    'tiempo_limite_minutos' => $examen->tiempo_limite_minutos,
                    'es_obligatorio' => $examen->es_obligatorio,
                    'activo' => $examen->activo,
                ]);

                foreach ($examen->preguntas as $pregunta) {
                    $nuevaPregunta = CapacitacionPregunta::create([
                        'workspace_id' => $workspace->id,
                        'examen_id' => $nuevoExamen->id,
                        'tipo' => $pregunta->tipo,
                        'enunciado' => $pregunta->enunciado,
                        'explicacion' => $pregunta->explicacion,
                        'puntaje' => $pregunta->puntaje,
                        'orden' => $pregunta->orden,
                    ]);

                    foreach ($pregunta->opciones as $opcion) {
                        CapacitacionOpcion::create([
                            'workspace_id' => $workspace->id,
                            'pregunta_id' => $nuevaPregunta->id,
                            'inciso' => $opcion->inciso,
                            'texto' => $opcion->texto,
                            'es_correcta' => $opcion->es_correcta,
                            'orden' => $opcion->orden,
                        ]);
                    }
                }
            }

            return $copia;
        });

        return response()->json([
            'message' => 'Curso duplicado como borrador.',
            'curso' => $this->loadFullAdminCurso($nuevoCurso),
        ], 201);
    }

    /**
     * Elimina un curso únicamente si es un borrador sin historial académico (sin asignaciones, intentos ni constancias).
     * Si ya tiene historial, devuelve 409 e indica archivarlo para proteger el expediente académico.
     */
    public function adminDestroyCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $tieneHistorial = $curso->asignaciones()->exists()
            || $curso->intentosExamen()->exists()
            || $curso->constancias()->exists();

        if ($tieneHistorial) {
            return response()->json([
                'message' => 'No se puede eliminar definitivamente un curso que ya cuenta con agentes asignados, evaluaciones o constancias emitidas. Utiliza la opción «Archivar curso» para retirarlo del catálogo conservando intacto el historial académico.',
                'code' => 'course_has_academic_history',
            ], 409);
        }

        DB::transaction(function () use ($curso) {
            foreach ($curso->recursos as $recurso) {
                if ($recurso->path && Storage::disk($recurso->disk ?: 'local')->exists($recurso->path)) {
                    Storage::disk($recurso->disk ?: 'local')->delete($recurso->path);
                }
            }

            if ($curso->portada_path && Storage::disk($curso->portada_disk ?: 'local')->exists($curso->portada_path)) {
                Storage::disk($curso->portada_disk ?: 'local')->delete($curso->portada_path);
            }

            if ($curso->logo_constancia_path && Storage::disk('local')->exists($curso->logo_constancia_path)) {
                Storage::disk('local')->delete($curso->logo_constancia_path);
            }

            $curso->delete();
        });

        return response()->json([
            'message' => 'Curso en borrador eliminado correctamente.',
        ]);
    }

    // =========================================================================
    // SECCIÓN 2: GESTIÓN DE MÓDULOS, LECCIONES Y RECURSOS
    // =========================================================================

    public function storeModulo(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:190'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $maxOrden = (int) $curso->modulos()->max('orden');

        $modulo = CapacitacionModulo::create([
            'workspace_id' => $workspace->id,
            'curso_id' => $curso->id,
            'titulo' => trim($validated['titulo']),
            'descripcion' => $validated['descripcion'] ?? null,
            'orden' => $maxOrden + 1,
        ]);

        return response()->json([
            'message' => 'Módulo agregado.',
            'modulo' => $modulo->load('lecciones.recursos'),
            'curso' => $this->loadFullAdminCurso($curso),
        ], 201);
    }

    public function updateModulo(Request $request, CapacitacionModulo $modulo): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $modulo->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:190'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $modulo->update([
            'titulo' => trim($validated['titulo']),
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        return response()->json([
            'message' => 'Módulo actualizado.',
            'modulo' => $modulo->fresh('lecciones.recursos'),
        ]);
    }

    public function destroyModulo(Request $request, CapacitacionModulo $modulo): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $modulo->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $leccionIds = $modulo->lecciones()->pluck('id')->all();
        if (! empty($leccionIds)) {
            $tieneProgreso = CapacitacionProgresoLeccion::query()
                ->whereIn('leccion_id', $leccionIds)
                ->exists();

            if ($tieneProgreso) {
                return response()->json([
                    'message' => 'No se puede eliminar este módulo porque existen agentes con progreso registrado en sus lecciones.',
                    'code' => 'module_has_progress',
                ], 409);
            }
        }

        $curso = $modulo->curso()->firstOrFail();

        DB::transaction(function () use ($modulo) {
            foreach ($modulo->lecciones()->with('recursos')->get() as $leccion) {
                foreach ($leccion->recursos as $recurso) {
                    if ($recurso->path && Storage::disk($recurso->disk ?: 'local')->exists($recurso->path)) {
                        Storage::disk($recurso->disk ?: 'local')->delete($recurso->path);
                    }
                }
            }
            $modulo->delete();
        });

        return response()->json([
            'message' => 'Módulo eliminado.',
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function reorderModulos(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'modulo_ids' => ['required', 'array', 'min:1'],
            'modulo_ids.*' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($curso, $validated) {
            foreach (array_values($validated['modulo_ids']) as $index => $moduloId) {
                CapacitacionModulo::query()
                    ->where('curso_id', $curso->id)
                    ->where('id', (int) $moduloId)
                    ->update(['orden' => $index + 1]);
            }
        });

        return response()->json([
            'message' => 'Orden de módulos actualizado.',
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function storeLeccion(Request $request, CapacitacionModulo $modulo): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $modulo->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:190'],
            'resumen' => ['nullable', 'string'],
            'tipo' => ['required', Rule::in(['video', 'pdf', 'documento', 'texto', 'enlace', 'evaluacion'])],
            'contenido_html' => ['nullable', 'string'],
            'video_proveedor' => ['nullable', Rule::in(['local', 'youtube', 'vimeo', 'externo'])],
            'video_url' => ['nullable', 'string', 'max:1000'],
            'duracion_minutos' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'duracion_segundos' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'es_obligatoria' => ['nullable', 'boolean'],
            'archivo_principal' => ['nullable', 'file', 'max:153600'], // Hasta 150 MB para video/PDF/documentos
            'archivos_apoyo' => ['nullable', 'array'],
            'archivos_apoyo.*' => ['file', 'max:51200'],
        ]);

        $curso = $modulo->curso()->firstOrFail();
        $maxOrden = (int) $modulo->lecciones()->max('orden');
        $duracionMin = (int) ($validated['duracion_minutos'] ?? 0);
        $duracionSeg = (int) ($validated['duracion_segundos'] ?? ($duracionMin * 60));

        $leccion = DB::transaction(function () use ($request, $workspace, $curso, $modulo, $validated, $maxOrden, $duracionMin, $duracionSeg) {
            $leccion = CapacitacionLeccion::create([
                'workspace_id' => $workspace->id,
                'curso_id' => $curso->id,
                'modulo_id' => $modulo->id,
                'titulo' => trim($validated['titulo']),
                'resumen' => $validated['resumen'] ?? null,
                'tipo' => $validated['tipo'],
                'contenido_html' => $validated['contenido_html'] ?? null,
                'video_proveedor' => $validated['video_proveedor'] ?? ($request->hasFile('archivo_principal') && $validated['tipo'] === 'video' ? 'local' : null),
                'video_url' => $validated['video_url'] ?? null,
                'duracion_minutos' => $duracionMin,
                'duracion_segundos' => $duracionSeg,
                'orden' => $maxOrden + 1,
                'es_obligatoria' => isset($validated['es_obligatoria']) ? (bool) $validated['es_obligatoria'] : true,
            ]);

            if ($request->hasFile('archivo_principal')) {
                $this->storeUploadedRecurso(
                    $request->file('archivo_principal'),
                    $workspace->id,
                    $curso->id,
                    $leccion->id,
                    $leccion->titulo,
                    true
                );
            }

            if ($request->hasFile('archivos_apoyo')) {
                foreach ($request->file('archivos_apoyo') as $idx => $file) {
                    $this->storeUploadedRecurso(
                        $file,
                        $workspace->id,
                        $curso->id,
                        $leccion->id,
                        $file->getClientOriginalName(),
                        false,
                        $idx + 1
                    );
                }
            }

            $this->syncCursoTotalDuration($curso);

            return $leccion;
        });

        return response()->json([
            'message' => 'Lección creada correctamente.',
            'leccion' => $leccion->load('recursos'),
            'curso' => $this->loadFullAdminCurso($curso),
        ], 201);
    }

    public function updateLeccion(Request $request, CapacitacionLeccion $leccion): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $leccion->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'titulo' => ['sometimes', 'required', 'string', 'max:190'],
            'resumen' => ['nullable', 'string'],
            'tipo' => ['sometimes', 'required', Rule::in(['video', 'pdf', 'documento', 'texto', 'enlace', 'evaluacion'])],
            'contenido_html' => ['nullable', 'string'],
            'video_proveedor' => ['nullable', Rule::in(['local', 'youtube', 'vimeo', 'externo'])],
            'video_url' => ['nullable', 'string', 'max:1000'],
            'duracion_minutos' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'duracion_segundos' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'es_obligatoria' => ['nullable', 'boolean'],
            'archivo_principal' => ['nullable', 'file', 'max:153600'],
            'archivos_apoyo' => ['nullable', 'array'],
            'archivos_apoyo.*' => ['file', 'max:51200'],
        ]);

        $curso = $leccion->curso()->firstOrFail();

        DB::transaction(function () use ($request, $workspace, $curso, $leccion, $validated) {
            if (array_key_exists('titulo', $validated)) {
                $leccion->titulo = trim($validated['titulo']);
            }
            if (array_key_exists('resumen', $validated)) {
                $leccion->resumen = $validated['resumen'];
            }
            if (array_key_exists('tipo', $validated)) {
                $leccion->tipo = $validated['tipo'];
            }
            if (array_key_exists('contenido_html', $validated)) {
                $leccion->contenido_html = $validated['contenido_html'];
            }
            if (array_key_exists('video_proveedor', $validated)) {
                $leccion->video_proveedor = $validated['video_proveedor'];
            }
            if (array_key_exists('video_url', $validated)) {
                $leccion->video_url = $validated['video_url'];
            }
            if (array_key_exists('duracion_minutos', $validated)) {
                $leccion->duracion_minutos = (int) $validated['duracion_minutos'];
                if (! array_key_exists('duracion_segundos', $validated)) {
                    $leccion->duracion_segundos = (int) $validated['duracion_minutos'] * 60;
                }
            }
            if (array_key_exists('duracion_segundos', $validated) && $validated['duracion_segundos'] !== null) {
                $leccion->duracion_segundos = (int) $validated['duracion_segundos'];
            }
            if (array_key_exists('es_obligatoria', $validated)) {
                $leccion->es_obligatoria = (bool) $validated['es_obligatoria'];
            }

            if ($request->hasFile('archivo_principal')) {
                // Reemplazar recurso principal anterior
                $prevPrincipales = $leccion->recursos()->where('es_principal', true)->get();
                foreach ($prevPrincipales as $prev) {
                    if ($prev->path && Storage::disk($prev->disk ?: 'local')->exists($prev->path)) {
                        Storage::disk($prev->disk ?: 'local')->delete($prev->path);
                    }
                    $prev->delete();
                }

                $this->storeUploadedRecurso(
                    $request->file('archivo_principal'),
                    $workspace->id,
                    $curso->id,
                    $leccion->id,
                    $leccion->titulo,
                    true
                );

                if ($leccion->tipo === 'video') {
                    $leccion->video_proveedor = 'local';
                }
            }

            if ($request->hasFile('archivos_apoyo')) {
                $maxOrden = (int) $leccion->recursos()->max('orden');
                foreach ($request->file('archivos_apoyo') as $idx => $file) {
                    $this->storeUploadedRecurso(
                        $file,
                        $workspace->id,
                        $curso->id,
                        $leccion->id,
                        $file->getClientOriginalName(),
                        false,
                        $maxOrden + $idx + 1
                    );
                }
            }

            $leccion->save();
            $this->syncCursoTotalDuration($curso);
        });

        return response()->json([
            'message' => 'Lección actualizada.',
            'leccion' => $leccion->fresh('recursos'),
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function destroyLeccion(Request $request, CapacitacionLeccion $leccion): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $leccion->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        if ($leccion->progresos()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar esta lección porque ya tiene progreso registrado por uno o más agentes.',
                'code' => 'lesson_has_progress',
            ], 409);
        }

        $curso = $leccion->curso()->firstOrFail();

        DB::transaction(function () use ($leccion, $curso) {
            foreach ($leccion->recursos as $recurso) {
                if ($recurso->path && Storage::disk($recurso->disk ?: 'local')->exists($recurso->path)) {
                    Storage::disk($recurso->disk ?: 'local')->delete($recurso->path);
                }
            }
            $leccion->delete();
            $this->syncCursoTotalDuration($curso);
        });

        return response()->json([
            'message' => 'Lección eliminada.',
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function reorderLecciones(Request $request, CapacitacionModulo $modulo): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $modulo->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'leccion_ids' => ['required', 'array', 'min:1'],
            'leccion_ids.*' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($modulo, $validated) {
            foreach (array_values($validated['leccion_ids']) as $index => $leccionId) {
                CapacitacionLeccion::query()
                    ->where('modulo_id', $modulo->id)
                    ->where('id', (int) $leccionId)
                    ->update(['orden' => $index + 1]);
            }
        });

        return response()->json([
            'message' => 'Orden de lecciones actualizado.',
            'curso' => $this->loadFullAdminCurso($modulo->curso()->firstOrFail()),
        ]);
    }

    public function destroyRecurso(Request $request, CapacitacionRecurso $recurso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $recurso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        if ($recurso->path && Storage::disk($recurso->disk ?: 'local')->exists($recurso->path)) {
            Storage::disk($recurso->disk ?: 'local')->delete($recurso->path);
        }

        $recurso->delete();

        return response()->json([
            'message' => 'Recurso eliminado.',
        ]);
    }

    // =========================================================================
    // SECCIÓN 3: EXÁMENES, PREGUNTAS Y OPCIONES
    // =========================================================================

    /**
     * Crea o actualiza un examen completo (con preguntas y opciones).
     * Protege los intentos históricos: actualiza en sitio o conserva referencias existentes.
     */
    public function saveExamen(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'modulo_id' => ['nullable', 'integer'],
            'leccion_id' => ['nullable', 'integer'],
            'titulo' => ['required', 'string', 'max:190'],
            'instrucciones' => ['nullable', 'string'],
            'calificacion_minima' => ['required', 'numeric', 'min:1', 'max:100'],
            'max_intentos' => ['required', 'integer', 'min:0', 'max:50'],
            'tiempo_limite_minutos' => ['nullable', 'integer', 'min:1', 'max:600'],
            'es_obligatorio' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
            'preguntas' => ['required', 'array', 'min:1'],
            'preguntas.*.id' => ['nullable', 'integer'],
            'preguntas.*.tipo' => ['required', Rule::in(['opcion_multiple', 'verdadero_falso'])],
            'preguntas.*.enunciado' => ['required', 'string'],
            'preguntas.*.explicacion' => ['nullable', 'string'],
            'preguntas.*.puntaje' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'preguntas.*.opciones' => ['required', 'array', 'min:2'],
            'preguntas.*.opciones.*.id' => ['nullable', 'integer'],
            'preguntas.*.opciones.*.inciso' => ['nullable', 'string', 'max:10'],
            'preguntas.*.opciones.*.texto' => ['required', 'string'],
            'preguntas.*.opciones.*.es_correcta' => ['required', 'boolean'],
        ]);

        // Validar que cada pregunta tenga al menos una opción correcta
        foreach ($validated['preguntas'] as $idx => $preguntaInput) {
            $tieneCorrecta = collect($preguntaInput['opciones'])->contains(fn ($op) => ! empty($op['es_correcta']));
            if (! $tieneCorrecta) {
                return response()->json([
                    'message' => sprintf('La pregunta #%d debe tener marcada una respuesta correcta.', $idx + 1),
                ], 422);
            }
        }

        $examen = DB::transaction(function () use ($workspace, $curso, $validated) {
            $examen = null;
            if (! empty($validated['id'])) {
                $examen = CapacitacionExamen::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('curso_id', $curso->id)
                    ->where('id', (int) $validated['id'])
                    ->firstOrFail();

                $examen->update([
                    'modulo_id' => $validated['modulo_id'] ?? null,
                    'leccion_id' => $validated['leccion_id'] ?? null,
                    'titulo' => trim($validated['titulo']),
                    'instrucciones' => $validated['instrucciones'] ?? null,
                    'calificacion_minima' => $validated['calificacion_minima'],
                    'max_intentos' => (int) $validated['max_intentos'],
                    'tiempo_limite_minutos' => $validated['tiempo_limite_minutos'] ?? null,
                    'es_obligatorio' => isset($validated['es_obligatorio']) ? (bool) $validated['es_obligatorio'] : true,
                    'activo' => isset($validated['activo']) ? (bool) $validated['activo'] : true,
                ]);
            } else {
                $examen = CapacitacionExamen::create([
                    'workspace_id' => $workspace->id,
                    'curso_id' => $curso->id,
                    'modulo_id' => $validated['modulo_id'] ?? null,
                    'leccion_id' => $validated['leccion_id'] ?? null,
                    'titulo' => trim($validated['titulo']),
                    'instrucciones' => $validated['instrucciones'] ?? null,
                    'calificacion_minima' => $validated['calificacion_minima'],
                    'max_intentos' => (int) $validated['max_intentos'],
                    'tiempo_limite_minutos' => $validated['tiempo_limite_minutos'] ?? null,
                    'es_obligatorio' => isset($validated['es_obligatorio']) ? (bool) $validated['es_obligatorio'] : true,
                    'activo' => isset($validated['activo']) ? (bool) $validated['activo'] : true,
                ]);
            }

            $keptPreguntaIds = [];
            $incisosDefault = ['A', 'B', 'C', 'D', 'E', 'F'];

            foreach (array_values($validated['preguntas']) as $pIndex => $pData) {
                $pregunta = null;
                if (! empty($pData['id'])) {
                    $pregunta = CapacitacionPregunta::query()
                        ->where('examen_id', $examen->id)
                        ->where('id', (int) $pData['id'])
                        ->first();
                }

                if ($pregunta) {
                    $pregunta->update([
                        'tipo' => $pData['tipo'],
                        'enunciado' => trim($pData['enunciado']),
                        'explicacion' => $pData['explicacion'] ?? null,
                        'puntaje' => $pData['puntaje'] ?? 1.00,
                        'orden' => $pIndex + 1,
                    ]);
                } else {
                    $pregunta = CapacitacionPregunta::create([
                        'workspace_id' => $workspace->id,
                        'examen_id' => $examen->id,
                        'tipo' => $pData['tipo'],
                        'enunciado' => trim($pData['enunciado']),
                        'explicacion' => $pData['explicacion'] ?? null,
                        'puntaje' => $pData['puntaje'] ?? 1.00,
                        'orden' => $pIndex + 1,
                    ]);
                }

                $keptPreguntaIds[] = $pregunta->id;
                $keptOpcionIds = [];

                foreach (array_values($pData['opciones']) as $oIndex => $oData) {
                    $opcion = null;
                    if (! empty($oData['id'])) {
                        $opcion = CapacitacionOpcion::query()
                            ->where('pregunta_id', $pregunta->id)
                            ->where('id', (int) $oData['id'])
                            ->first();
                    }

                    $inciso = ! empty($oData['inciso'])
                        ? strtoupper(trim($oData['inciso']))
                        : ($incisosDefault[$oIndex] ?? (string) ($oIndex + 1));

                    if ($opcion) {
                        $opcion->update([
                            'inciso' => $inciso,
                            'texto' => trim($oData['texto']),
                            'es_correcta' => (bool) $oData['es_correcta'],
                            'orden' => $oIndex + 1,
                        ]);
                    } else {
                        $opcion = CapacitacionOpcion::create([
                            'workspace_id' => $workspace->id,
                            'pregunta_id' => $pregunta->id,
                            'inciso' => $inciso,
                            'texto' => trim($oData['texto']),
                            'es_correcta' => (bool) $oData['es_correcta'],
                            'orden' => $oIndex + 1,
                        ]);
                    }

                    $keptOpcionIds[] = $opcion->id;
                }

                // Eliminar opciones descartadas (las respuestas históricas tienen nullOnDelete y conservan snapshot)
                CapacitacionOpcion::query()
                    ->where('pregunta_id', $pregunta->id)
                    ->whereNotIn('id', $keptOpcionIds)
                    ->delete();
            }

            CapacitacionPregunta::query()
                ->where('examen_id', $examen->id)
                ->whereNotIn('id', $keptPreguntaIds)
                ->delete();

            return $examen;
        });

        return response()->json([
            'message' => 'Evaluación guardada correctamente.',
            'examen' => $this->exposeExamAnswersForAdmin($examen->load('preguntas.opciones')),
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function destroyExamen(Request $request, CapacitacionExamen $examen): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $examen->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        if ($examen->intentos()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar este examen porque ya existen intentos calificados de agentes. Puedes desactivarlo para conservar el historial de calificaciones.',
                'code' => 'exam_has_attempts',
            ], 409);
        }

        $curso = $examen->curso()->firstOrFail();
        $examen->delete();

        return response()->json([
            'message' => 'Evaluación eliminada.',
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    // =========================================================================
    // SECCIÓN 4: ASIGNACIÓN DE CURSOS Y CONSTANCIAS ADMINISTRATIVAS
    // =========================================================================

    public function assignCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();
        $this->capacitaciones->requireAdmin($user, $workspace);

        if ($curso->estado !== CapacitacionCurso::ESTADO_PUBLICADO) {
            return response()->json([
                'message' => 'Solo se pueden asignar cursos que estén en estado «Publicado».',
            ], 422);
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer'],
            'fecha_limite' => ['nullable', 'date'],
        ]);

        $result = $this->capacitaciones->assignCourseToUsers(
            $workspace,
            $curso,
            $validated['user_ids'],
            $validated['fecha_limite'] ?? null,
            $user
        );

        return response()->json([
            'message' => sprintf(
                'Asignación completada: %d nueva(s), %d ya asignada(s) previamente.',
                $result['created'],
                $result['skipped']
            ),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'curso' => $this->loadFullAdminCurso($curso),
        ]);
    }

    public function adminConstancias(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->requireAdmin($request->user(), $workspace);

        $constancias = CapacitacionConstancia::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('fecha_emision')
            ->get();

        return response()->json([
            'constancias' => $constancias,
        ]);
    }

    // =========================================================================
    // SECCIÓN 5: PORTAL DEL ALUMNO / AGENTE («MIS CAPACITACIONES» Y «AULA»)
    // =========================================================================

    /**
     * Devuelve el resumen y listado de cursos asignados al usuario autenticado en el workspace activo,
     * además del indicador `can_manage_capacitaciones` para habilitar la pestaña de Academia si es admin.
     */
    public function misCursos(Request $request): JsonResponse
    {
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id);
        $canManage = $this->capacitaciones->canManageCapacitaciones($user, $workspace);

        $asignaciones = CapacitacionAsignacion::query()
            ->with([
                'curso' => function ($q) {
                    $q->withCount(['modulos', 'lecciones', 'examenes']);
                },
                'ultimaLeccion:id,modulo_id,titulo,tipo',
                'constancia:id,asignacion_id,folio,codigo_verificacion,nombre_agente,nombre_curso,duracion_texto,calificacion_obtenida,fecha_emision',
            ])
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->orderByRaw("FIELD(estado, 'en_progreso', 'pendiente', 'completado', 'vencido')")
            ->orderByDesc('updated_at')
            ->get();

        $pendientes = $asignaciones->where('estado', CapacitacionAsignacion::ESTADO_PENDIENTE)->count();
        $enProgreso = $asignaciones->where('estado', CapacitacionAsignacion::ESTADO_EN_PROGRESO)->count();
        $completados = $asignaciones->where('estado', CapacitacionAsignacion::ESTADO_COMPLETADO)->count();
        $porcentajeGlobal = $asignaciones->isNotEmpty()
            ? round((float) $asignaciones->avg('porcentaje_avance'), 1)
            : 0.0;
        $constanciasCount = $asignaciones->filter(fn ($a) => $a->constancia !== null)->count();

        return response()->json([
            'can_manage_capacitaciones' => $canManage,
            'kpis' => [
                'total_asignados' => $asignaciones->count(),
                'pendientes' => $pendientes,
                'en_progreso' => $enProgreso,
                'completados' => $completados,
                'porcentaje_global' => $porcentajeGlobal,
                'constancias_obtenidas' => $constanciasCount,
            ],
            'asignaciones' => $asignaciones,
        ]);
    }

    /**
     * Carga el Aula Virtual de un curso para el agente asignado (o vista previa para el administrador).
     * NUNCA expone `es_correcta` ni `explicacion` en las preguntas de examen al alumno.
     */
    public function aulaCurso(Request $request, CapacitacionCurso $curso): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $canManage = $this->capacitaciones->canManageCapacitaciones($user, $workspace);

        $asignacion = CapacitacionAsignacion::query()
            ->with(['constancia'])
            ->where('workspace_id', $workspace->id)
            ->where('curso_id', $curso->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $asignacion && ! $canManage) {
            return response()->json([
                'message' => 'Este curso no se encuentra asignado a tu cuenta en esta promotoría.',
                'code' => 'course_not_assigned',
            ], 403);
        }

        if ($asignacion) {
            $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id, $asignacion);
        }

        $curso->load([
            'modulos.lecciones.recursos',
            'examenes' => function ($q) {
                $q->where('activo', true)->with(['preguntas.opciones']);
            },
        ]);

        // Asegurar que las respuestas correctas permanezcan ocultas en la vista de Aula
        foreach ($curso->examenes as $examen) {
            foreach ($examen->preguntas as $pregunta) {
                $pregunta->makeHidden(['explicacion']);
                foreach ($pregunta->opciones as $opcion) {
                    $opcion->makeHidden(['es_correcta']);
                }
            }
        }

        $progresoMap = $asignacion
            ? CapacitacionProgresoLeccion::query()
                ->where('asignacion_id', $asignacion->id)
                ->get()
                ->keyBy('leccion_id')
            : collect();

        $intentosPorExamen = $asignacion
            ? CapacitacionIntentoExamen::query()
                ->where('asignacion_id', $asignacion->id)
                ->orderByDesc('numero_intento')
                ->get()
                ->groupBy('examen_id')
            : collect();

        return response()->json([
            'curso' => $curso,
            'asignacion' => $asignacion,
            'es_vista_previa_admin' => $asignacion === null && $canManage,
            'progreso_lecciones' => $progresoMap,
            'intentos_examen' => $intentosPorExamen,
        ]);
    }

    /**
     * Registra el progreso de visualización o finalización de una lección por parte del alumno.
     */
    public function registrarProgresoLeccion(Request $request, CapacitacionLeccion $leccion): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $leccion->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $asignacion = CapacitacionAsignacion::query()
            ->where('workspace_id', $workspace->id)
            ->where('curso_id', $leccion->curso_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $asignacion) {
            return response()->json([
                'message' => 'Debes tener el curso asignado para registrar progreso en sus lecciones.',
            ], 403);
        }

        $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id, $asignacion);

        $validated = $request->validate([
            'segundos_vistos' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'duracion_total_segundos' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'completada' => ['nullable', 'boolean'],
        ]);

        $result = $this->capacitaciones->recordLessonProgress(
            $asignacion,
            $leccion,
            (int) ($validated['segundos_vistos'] ?? 0),
            (int) ($validated['duracion_total_segundos'] ?? 0),
            (bool) ($validated['completada'] ?? false)
        );

        return response()->json([
            'message' => $result['progreso_leccion']->estado === CapacitacionProgresoLeccion::ESTADO_COMPLETADA
                ? 'Lección completada correctamente.'
                : 'Avance de lección guardado.',
            'progreso_leccion' => $result['progreso_leccion'],
            'asignacion' => $result['asignacion'],
            'constancia_emitida' => $result['constancia_emitida'],
        ]);
    }

    /**
     * Recibe y califica un intento de examen en el servidor.
     */
    public function entregarExamen(Request $request, CapacitacionExamen $examen): JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $examen->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $asignacion = CapacitacionAsignacion::query()
            ->where('workspace_id', $workspace->id)
            ->where('curso_id', $examen->curso_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $asignacion) {
            return response()->json([
                'message' => 'Debes tener el curso asignado para presentar esta evaluación.',
            ], 403);
        }

        $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id, $asignacion);

        $validated = $request->validate([
            'respuestas' => ['required', 'array'],
        ]);

        $result = $this->capacitaciones->submitExamAttempt(
            $asignacion,
            $examen,
            $user,
            $validated['respuestas']
        );

        return response()->json([
            'message' => $result['intento']->aprobado
                ? sprintf('¡Felicidades! Aprobaste la evaluación con %s%%.', number_format((float) $result['intento']->calificacion, 1))
                : sprintf('Obtuviste %s%%. La calificación mínima aprobatoria es %s%%.', number_format((float) $result['intento']->calificacion, 1), number_format((float) $examen->calificacion_minima, 1)),
            'intento' => $result['intento'],
            'intentos_restantes' => $result['intentos_restantes'],
            'asignacion' => $result['asignacion'],
            'constancia_emitida' => $result['constancia_emitida'],
        ]);
    }

    // =========================================================================
    // SECCIÓN 6: STREAMING PRIVADO DE RECURSOS Y DESCARGA DE CONSTANCIA PDF
    // =========================================================================

    /**
     * Sirve de forma privada la portada de un curso únicamente a miembros activos de su workspace.
     */
    public function streamPortada(Request $request, CapacitacionCurso $curso): BinaryFileResponse|JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $curso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        $this->capacitaciones->ensureActiveLearnerAccess($request->user(), (int) $workspace->id);

        $disk = $curso->portada_disk ?: 'local';
        if (! $curso->portada_path || ! Storage::disk($disk)->exists($curso->portada_path)) {
            return response()->json(['message' => 'Portada no encontrada.'], 404);
        }

        $fullPath = Storage::disk($disk)->path($curso->portada_path);

        return response()->file($fullPath, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Transmite de forma segura un recurso educativo privado (con soporte HTTP Range 206 para video HTML5)
     * verificando pertenencia al workspace y asignación activa o rol administrador.
     */
    public function streamRecurso(Request $request, CapacitacionRecurso $recurso): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $recurso->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $canManage = $this->capacitaciones->canManageCapacitaciones($user, $workspace);
        if (! $canManage) {
            $asignacion = CapacitacionAsignacion::query()
                ->where('workspace_id', $workspace->id)
                ->where('curso_id', $recurso->curso_id)
                ->where('user_id', $user->id)
                ->first();

            if (! $asignacion) {
                return response()->json([
                    'message' => 'No tienes acceso a los recursos de este curso.',
                ], 403);
            }

            $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id, $asignacion);
        }

        $disk = $recurso->disk ?: 'local';
        if (! $recurso->path || ! Storage::disk($disk)->exists($recurso->path)) {
            return response()->json(['message' => 'Archivo educativo no encontrado.'], 404);
        }

        $fullPath = Storage::disk($disk)->path($recurso->path);
        $mime = $recurso->mime_type ?: (mime_content_type($fullPath) ?: 'application/octet-stream');
        $fileSize = filesize($fullPath);
        $fileName = $recurso->nombre_original ?: basename($fullPath);

        if ($request->boolean('download') && $recurso->es_descargable) {
            return response()->download($fullPath, $fileName, [
                'Content-Type' => $mime,
            ]);
        }

        // Soporte HTTP Range (206 Partial Content) para reproducción fluida de videos y PDFs en navegador
        $rangeHeader = $request->header('Range');
        if ($rangeHeader && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? (int) $matches[2] : ($fileSize - 1);
            $end = min($end, $fileSize - 1);

            if ($start > $end || $start >= $fileSize) {
                return response()->stream(fn () => null, 416, [
                    'Content-Range' => "bytes */{$fileSize}",
                ]);
            }

            $length = $end - $start + 1;

            return response()->stream(function () use ($fullPath, $start, $length) {
                $handle = fopen($fullPath, 'rb');
                if ($handle === false) {
                    return;
                }
                fseek($handle, $start);
                $remaining = $length;
                while ($remaining > 0 && ! feof($handle)) {
                    $chunk = fread($handle, min(65536, $remaining));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();
                }
                fclose($handle);
            }, 206, [
                'Content-Type' => $mime,
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Descarga la constancia PDF emitida. Solo autoriza al titular de la constancia en el workspace
     * o al propietario/administrador de capacitaciones del mismo workspace.
     */
    public function descargarConstancia(Request $request, CapacitacionConstancia $constancia): BinaryFileResponse|JsonResponse
    {
        $this->abortIfOutOfWorkspace($request, (int) $constancia->workspace_id);
        $workspace = $this->resolveActiveWorkspace($request);
        /** @var User $user */
        $user = $request->user();

        $canManage = $this->capacitaciones->canManageCapacitaciones($user, $workspace);
        $isOwner = (int) $constancia->user_id === (int) $user->id;

        if (! $canManage && ! $isOwner) {
            return response()->json([
                'message' => 'No tienes permiso para descargar la constancia de otro agente.',
            ], 403);
        }

        if ($isOwner && ! $canManage) {
            $this->capacitaciones->ensureActiveLearnerAccess($user, (int) $workspace->id);
        }

        $disk = $constancia->disk ?: 'local';
        if (! $constancia->pdf_path || ! Storage::disk($disk)->exists($constancia->pdf_path)) {
            $curso = $constancia->curso;
            $stored = $this->pdfService->generateAndStore($constancia, $curso);
            $constancia->update([
                'disk' => $stored['disk'],
                'pdf_path' => $stored['path'],
                'sha256_hash' => $stored['sha256'],
            ]);
            $disk = $stored['disk'];
        }

        $fullPath = Storage::disk($disk)->path($constancia->pdf_path);
        $downloadName = sprintf('Constancia_%s.pdf', $constancia->folio);

        return response()->download($fullPath, $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // =========================================================================
    // MÉTODOS PRIVADOS DE APOYO
    // =========================================================================

    private function loadFullAdminCurso(CapacitacionCurso $curso): CapacitacionCurso
    {
        $curso->load([
            'modulos.lecciones.recursos',
            'examenes.preguntas.opciones',
            'asignaciones' => function ($q) {
                $q->with(['constancia'])->orderByDesc('fecha_asignacion');
            },
        ])->loadCount(['modulos', 'lecciones', 'examenes', 'asignaciones', 'constancias']);

        foreach ($curso->examenes as $examen) {
            $this->exposeExamAnswersForAdmin($examen);
        }

        return $curso;
    }

    private function exposeExamAnswersForAdmin(CapacitacionExamen $examen): CapacitacionExamen
    {
        foreach ($examen->preguntas as $pregunta) {
            $pregunta->makeVisible(['explicacion']);
            foreach ($pregunta->opciones as $opcion) {
                $opcion->makeVisible(['es_correcta']);
            }
        }

        return $examen;
    }

    private function storeUploadedRecurso(
        \Illuminate\Http\UploadedFile $file,
        int $workspaceId,
        int $cursoId,
        int $leccionId,
        string $titulo,
        bool $esPrincipal,
        int $orden = 1
    ): CapacitacionRecurso {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        $tipo = 'documento';
        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            $tipo = 'video';
        } elseif ($mime === 'application/pdf' || $ext === 'pdf') {
            $tipo = 'pdf';
        } elseif (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $tipo = 'imagen';
        }

        $folder = sprintf(
            'capacitaciones/workspace_%d/curso_%d/leccion_%d',
            $workspaceId,
            $cursoId,
            $leccionId
        );

        $safeFilename = Str::uuid()->toString() . ($ext ? '.' . $ext : '');
        $storedPath = $file->storeAs($folder, $safeFilename, 'local');

        return CapacitacionRecurso::create([
            'workspace_id' => $workspaceId,
            'curso_id' => $cursoId,
            'leccion_id' => $leccionId,
            'tipo' => $tipo,
            'titulo' => $titulo ?: $file->getClientOriginalName(),
            'nombre_original' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'disk' => 'local',
            'path' => $storedPath,
            'es_principal' => $esPrincipal,
            'es_descargable' => $tipo !== 'video',
            'orden' => $orden,
        ]);
    }

    private function syncCursoTotalDuration(CapacitacionCurso $curso): void
    {
        $totalMinutos = (int) $curso->lecciones()->sum('duracion_minutos');
        if ($totalMinutos > 0) {
            $curso->update(['duracion_minutos' => $totalMinutos]);
        }
    }
}

