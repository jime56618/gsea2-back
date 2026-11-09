<?php

namespace App\Http\Controllers\Api;

use App\Models\CobranzaCuota;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Models\Poliza;
use App\Models\Tramite;
use App\Models\Contratante;
use App\Support\WorkspacePermissions;
use App\Support\WorkspacePermissions as P;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends BaseApiController
{
    public function totales(Request $request)
    {
        $this->requirePermission($request, P::DASHBOARD_VIEW);
        $ws = $this->resolveWorkspaceId($request);
        $data = $request->validate(['workspace_id' => 'sometimes|integer|min:1']);
        $this->resolveWorkspaceId($request, isset($data['workspace_id']) ? (int) $data['workspace_id'] : null);
        $permissions = app(WorkspaceAuthorizationService::class)->permissionNamesForUser($request->user(), $ws);
        return response()->json([
            'workspace_id' => $ws,
            'clientes_total' => in_array(P::CONTRATANTES_VIEW, $permissions, true)
                ? Contratante::where('workspace_id', $ws)->count() : null,
            // Mismo corte de fecha que el listado existente /polizas?vencidas=1.
            'polizas_vencidas' => in_array(P::POLIZAS_VIEW, $permissions, true)
                ? Poliza::where('workspace_id', $ws)->whereDate('fin_vigencia', '<', now()->toDateString())->count() : null,
        ]);
    }

    public function summary(Request $request)
    {
        $this->requirePermission($request, P::DASHBOARD_VIEW);
        $ws = $this->resolveWorkspaceId($request);
        $data = $request->validate([
            'workspace_id' => 'sometimes|integer|min:1',
            'compact' => 'sometimes|boolean',
            'from' => 'nullable|required_with:to|date_format:Y-m-d',
            'to' => 'nullable|required_with:from|date_format:Y-m-d|after_or_equal:from',
            'aseguradora_id' => 'nullable|integer|exists:aseguradoras,id',
            'agente_id' => ['nullable', 'integer', Rule::exists('agente_workspaces', 'id')->where('workspace_id', $ws)],
            'rango' => 'sometimes|in:0_7,8_15,16_30',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'polizas_page' => 'sometimes|integer|min:1',
            'tramites_page' => 'sometimes|integer|min:1',
            'etapas_pendientes' => 'sometimes|array|min:1',
            'etapas_pendientes.*' => ['required', 'string', 'distinct', Rule::exists('tramites', 'etapa')->where('workspace_id', $ws)],
            'semaforo' => ['sometimes', 'string', Rule::exists('tramites', 'semaforo')->where('workspace_id', $ws)],
        ]);
        $this->resolveWorkspaceId($request, isset($data['workspace_id']) ? (int) $data['workspace_id'] : null);
        $today = Carbon::today('America/Merida');
        $perPage = $data['per_page'] ?? 15;
        $auth = app(WorkspaceAuthorizationService::class);
        $permissions = $auth->permissionNamesForUser($request->user(), $ws);
        $can = fn ($permission) => in_array($permission, $permissions, true);
        $compact = (bool) ($data['compact'] ?? false);
        $result = [
            'workspace_id' => $ws, 'fecha_corte' => $today->toDateString(), 'timezone' => 'America/Merida',
            'polizas_por_vencer' => null, 'resumen_periodo' => null, 'tramites' => null,
        ];
        if ($can(P::POLIZAS_VIEW)) {
            $base = Poliza::query()->where('polizas.workspace_id', $ws);
            foreach (['aseguradora_id', 'agente_id'] as $filter) {
                if (isset($data[$filter])) {
                    $base->where('polizas.' . $filter, $data[$filter]);
                }
            }
            $ranges = ['0_7' => [0, 7], '8_15' => [8, 15], '16_30' => [16, 30]];
            $d0 = $today->toDateString();
            $d7 = $today->copy()->addDays(7)->toDateString();
            $d8 = $today->copy()->addDays(8)->toDateString();
            $d15 = $today->copy()->addDays(15)->toDateString();
            $d16 = $today->copy()->addDays(16)->toDateString();
            $d30 = $today->copy()->addDays(30)->toDateString();

            $rangeRow = (clone $base)
                ->whereDate('fin_vigencia', '>=', $d0)
                ->whereDate('fin_vigencia', '<=', $d30)
                ->selectRaw("
                    COUNT(CASE WHEN date(fin_vigencia) >= ? AND date(fin_vigencia) <= ? THEN 1 END) as c_0_7,
                    COUNT(CASE WHEN date(fin_vigencia) >= ? AND date(fin_vigencia) <= ? THEN 1 END) as c_8_15,
                    COUNT(CASE WHEN date(fin_vigencia) >= ? AND date(fin_vigencia) <= ? THEN 1 END) as c_16_30
                ", [$d0, $d7, $d8, $d15, $d16, $d30])
                ->first();

            $counts = [
                '0_7' => (int) ($rangeRow->c_0_7 ?? 0),
                '8_15' => (int) ($rangeRow->c_8_15 ?? 0),
                '16_30' => (int) ($rangeRow->c_16_30 ?? 0),
            ];

            [$min, $max] = isset($data['rango']) ? $ranges[$data['rango']] : [0, 30];
            $list = null;
            if (! $compact) {
                $rangeQuery = fn ($min, $max) => (clone $base)
                    ->whereDate('fin_vigencia', '>=', $today->copy()->addDays($min)->toDateString())
                    ->whereDate('fin_vigencia', '<=', $today->copy()->addDays($max)->toDateString());
                $list = $rangeQuery($min, $max)->with([
                    'contratante' => fn ($q) => $q->where('workspace_id', $ws), 'aseguradora',
                ])->orderBy('fin_vigencia')->orderBy('id')
                    ->paginate($perPage, ['*'], 'polizas_page')->withQueryString();
                $list->through(fn (Poliza $p) => [
                    'id' => $p->id, 'numero_poliza' => $p->numero_poliza,
                    'contratante' => $p->contratante ? ['id' => $p->contratante->id, 'nombre' => $p->contratante->nombre] : null,
                    'aseguradora' => $p->aseguradora ? ['id' => $p->aseguradora->id, 'nombre' => $p->aseguradora->nombre] : null,
                    'fin_vigencia' => $p->fin_vigencia?->toDateString(),
                ]);
            }
            $result['polizas_por_vencer'] = ['cantidades' => $counts, 'total' => array_sum($counts), 'listado' => $list];
            $from = $data['from'] ?? $today->copy()->startOfMonth()->toDateString();
            $to = $data['to'] ?? $today->toDateString();
            $period = (clone $base)->whereDate('fecha_emision', '>=', $from)->whereDate('fecha_emision', '<=', $to);
            $porMoneda = (clone $period)->select('moneda')
                ->selectRaw('COUNT(*) as polizas_emitidas, COALESCE(SUM(prima_total), 0) as prima_total')
                ->groupBy('moneda')->orderBy('moneda')->get()->map(fn ($row) => [
                    'moneda' => $row->moneda, 'polizas_emitidas' => (int) $row->polizas_emitidas,
                    'prima_total' => (string) $row->prima_total,
                ]);
            $result['resumen_periodo'] = [
                'from' => $from, 'to' => $to, 'campo_fecha' => 'fecha_emision',
                'aseguradora_id' => $data['aseguradora_id'] ?? null, 'agente_id' => $data['agente_id'] ?? null,
                'polizas_emitidas' => (int) $porMoneda->sum('polizas_emitidas'),
                'por_moneda' => $porMoneda,
            ];

            // Agregados de solo lectura; se respeta el workspace y filtros de pólizas.
            // Serie de 12 meses: prima emitida MXN, no número de pólizas.
            $startMonth = $today->copy()->startOfMonth()->subMonths(11);
            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $periodExpr = $isSqlite ? "strftime('%Y-%m', fecha_emision)" : "DATE_FORMAT(fecha_emision, '%Y-%m')";
            $monthly = (clone $base)->where('moneda', 'MXN')
                ->whereDate('fecha_emision', '>=', $startMonth->toDateString())
                ->whereDate('fecha_emision', '<=', $today->toDateString())
                ->selectRaw("{$periodExpr} as periodo, COALESCE(SUM(prima_total), 0) as ventas")
                ->groupByRaw($periodExpr)
                ->pluck('ventas', 'periodo');
            $ventas = [];
            for ($i = 0; $i < 12; $i++) {
                $month = $startMonth->copy()->addMonths($i);
                $key = $month->format('Y-m');
                $ventas[] = ['name' => $month->locale('es')->translatedFormat('M y'),
                    'periodo' => $key, 'ventas' => (float) ($monthly[$key] ?? 0)];
            }
            $porRamo = (clone $base)->leftJoin('ramos', 'polizas.ramo_id', '=', 'ramos.id')
                ->selectRaw("COALESCE(ramos.nombre, 'Sin ramo') as name, COUNT(polizas.id) as value")
                ->groupBy('ramos.id', 'ramos.nombre')->orderByDesc('value')->get()
                ->map(fn ($row) => ['name' => $row->name, 'value' => (int) $row->value]);
            $porAseguradora = (clone $base)->leftJoin('aseguradoras', 'polizas.aseguradora_id', '=', 'aseguradoras.id')
                ->selectRaw("polizas.aseguradora_id, COALESCE(aseguradoras.nombre, 'Sin aseguradora') as name, COUNT(polizas.id) as polizas")
                ->groupBy('polizas.aseguradora_id', 'aseguradoras.nombre')->orderByDesc('polizas')->get()
                ->map(fn ($row) => ['aseguradora_id' => $row->aseguradora_id, 'name' => $row->name,
                    'polizas' => (int) $row->polizas]);
            $result['graficas'] = [
                'ventas_mensuales_mxn' => $ventas,
                'polizas_por_ramo' => $porRamo,
                'cartera_por_aseguradora' => $porAseguradora,
            ];
            $result['polizas_vencidas'] = (clone $base)->whereDate('fin_vigencia', '<', $today->toDateString())->count();
        }
        if ($can(P::CONTRATANTES_VIEW)) {
            $result['clientes_total'] = Contratante::where('workspace_id', $ws)->count();
        }
        if ($can(P::TRAMITES_VIEW)) {
            $query = Tramite::query()->where('workspace_id', $ws);
            // Independiente de etapa y semáforo, sobre todos los trámites del workspace.
            $hasEstado = $request->attributes->get('__tramites_has_estado');
            if ($hasEstado === null) {
                $hasEstado = Schema::hasColumn('tramites', 'estado');
                $request->attributes->set('__tramites_has_estado', $hasEstado);
            }
            $estados = null;
            if ($hasEstado) {
                $estadosRow = (clone $query)->selectRaw("
                    COUNT(CASE WHEN estado = 'en_atencion' THEN 1 END) as en_atencion,
                    COUNT(CASE WHEN estado IS NULL THEN 1 END) as sin_estado
                ")->first();
                $estados = [
                    'en_atencion' => (int) ($estadosRow->en_atencion ?? 0),
                    'sin_estado' => (int) ($estadosRow->sin_estado ?? 0),
                ];
            }
            $stages = (clone $query)->select('etapa')->selectRaw('COUNT(*) as total')->groupBy('etapa')->orderBy('etapa')->get();
            if (isset($data['etapas_pendientes'])) {
                $query->whereIn('etapa', $data['etapas_pendientes']);
            }
            if (isset($data['semaforo'])) {
                $query->where('semaforo', $data['semaforo']);
            }
            $totals = ! isset($data['etapas_pendientes']) && ! isset($data['semaforo']) ? $stages
                : (clone $query)->select('etapa')->selectRaw('COUNT(*) as total')->groupBy('etapa')->orderBy('etapa')->get();
            // Clasificación de lectura; no modifica el semáforo almacenado.
            $semaforos = (clone $query)->selectRaw("CASE
                WHEN LOWER(TRIM(semaforo)) IN ('verde', 'amarillo', 'rojo') THEN LOWER(TRIM(semaforo))
                ELSE 'sin_semaforo_valido' END as color, COUNT(*) as total")
                ->groupBy('color')->orderBy('color')->get()
                ->map(fn ($row) => ['semaforo' => $row->color, 'total' => (int) $row->total]);
            // No existe catalogo de cierre ni jerarquia de semaforos en el backend.
            $list = $compact ? null : $query->orderByDesc('fecha_ultima_modificacion')->orderByDesc('id')
                ->paginate($perPage, ['id', 'folio', 'tipo', 'tipo_tramite', 'etapa', 'semaforo', 'fecha_ultima_modificacion'], 'tramites_page')
                ->withQueryString();
            $result['tramites'] = [
                'etapas_disponibles' => $stages, 'pendientes_identificados' => isset($data['etapas_pendientes']),
                'etapas_pendientes' => $data['etapas_pendientes'] ?? null,
                'totales_por_etapa' => $totals, 'total' => $totals->sum('total'),
                'totales_por_semaforo' => $semaforos,
                'estados' => $estados,
                'sin_estado_conocido' => $estados === null ? (int) $stages->sum('total') : $estados['sin_estado'],
                'orden' => 'fecha_ultima_modificacion_desc,id_desc', 'criterio_prioridad' => null, 'listado' => $list,
            ];
        }

        return response()->json($result);
    }

    /**
     * Resumen de cobranza para el dashboard con cuotas vencidas, de hoy, próximos 7 días,
     * desglose por moneda y top 5 casos prioritarios.
     */
    public function cobranzaSummary(Request $request)
    {
        $workspaceId = $this->resolveWorkspaceId($request);

        $user = $request->user();
        $auth = app(\App\Services\Authorization\WorkspaceAuthorizationService::class);
        $hasDashboard = $auth->hasPermission($user, $workspaceId, WorkspacePermissions::DASHBOARD_VIEW);
        $hasCobranza = $auth->hasPermission($user, $workspaceId, WorkspacePermissions::COBRANZA_VIEW);

        if (! $hasDashboard && ! $hasCobranza) {
            $this->requirePermission($request, WorkspacePermissions::DASHBOARD_VIEW);
        }

        $today = Carbon::today();
        $todayStr = $today->toDateString();
        $tomorrowStr = $today->copy()->addDay()->toDateString();
        $in7DaysStr = $today->copy()->addDays(7)->toDateString();

        $buildBaseQuery = function () use ($request, $workspaceId) {
            $query = CobranzaCuota::query()
                ->join('polizas', 'cobranza_cuotas.poliza_id', '=', 'polizas.id')
                ->where('cobranza_cuotas.workspace_id', $workspaceId)
                ->whereNotIn('cobranza_cuotas.estatus', ['pagado', 'cancelado']);

            if ($request->filled('agente_id')) {
                $agenteId = $request->integer('agente_id');
                $query->where(function ($q) use ($agenteId) {
                    $q->where('polizas.agente_id', $agenteId)
                      ->orWhereExists(function ($sub) use ($agenteId) {
                          $sub->select(DB::raw(1))
                              ->from('agente_workspaces')
                              ->whereColumn('agente_workspaces.id', 'polizas.agente_id')
                              ->where('agente_workspaces.agente_id', $agenteId);
                      });
                });
            }

            if ($request->filled('aseguradora_id')) {
                $query->where('polizas.aseguradora_id', $request->integer('aseguradora_id'));
            }

            if ($request->filled('ramo_id')) {
                $query->where('polizas.ramo_id', $request->integer('ramo_id'));
            }

            return $query;
        };

        $groupedRows = (clone $buildBaseQuery())
            ->selectRaw("
                COALESCE(NULLIF(polizas.moneda, ''), 'MXN') as moneda,
                CASE
                    WHEN date(cobranza_cuotas.fecha_programada) < ? THEN 'vencida'
                    WHEN date(cobranza_cuotas.fecha_programada) = ? THEN 'hoy'
                    WHEN date(cobranza_cuotas.fecha_programada) >= ? AND date(cobranza_cuotas.fecha_programada) <= ? THEN 'proximos'
                    ELSE 'otra'
                END as bucket,
                COUNT(cobranza_cuotas.id) as cantidad,
                COALESCE(SUM(cobranza_cuotas.monto), 0) as monto
            ", [$todayStr, $todayStr, $tomorrowStr, $in7DaysStr])
            ->groupBy('moneda', 'bucket')
            ->get();

        $formatBucket = function ($rows) {
            $totalCantidad = 0;
            $totalMonto = 0.0;
            $montosPorMoneda = [];
            $porMoneda = [];

            $byCurrency = [];
            foreach ($rows as $row) {
                $moneda = (string) $row->moneda;
                if (! isset($byCurrency[$moneda])) {
                    $byCurrency[$moneda] = ['cantidad' => 0, 'monto' => 0.0];
                }
                $byCurrency[$moneda]['cantidad'] += (int) $row->cantidad;
                $byCurrency[$moneda]['monto'] += (float) $row->monto;
            }

            foreach ($byCurrency as $moneda => $data) {
                $cant = $data['cantidad'];
                $monto = $data['monto'];
                $totalCantidad += $cant;
                $totalMonto += $monto;
                $montosPorMoneda[$moneda] = round($monto, 2);
                $porMoneda[] = [
                    'moneda' => $moneda,
                    'cantidad' => $cant,
                    'monto' => round($monto, 2),
                ];
            }

            if (empty($montosPorMoneda)) {
                $montosPorMoneda['MXN'] = 0.0;
            }

            return [
                'total' => $totalCantidad,
                'cantidad' => $totalCantidad,
                'monto_total' => round($totalMonto, 2),
                'montos_por_moneda' => $montosPorMoneda,
                'por_moneda' => $porMoneda,
            ];
        };

        $resumenVencidas = $formatBucket($groupedRows->where('bucket', 'vencida'));
        $resumenHoy = $formatBucket($groupedRows->where('bucket', 'hoy'));
        $resumenProximos = $formatBucket($groupedRows->where('bucket', 'proximos'));
        $totalesGenerales = $formatBucket($groupedRows);

        // Solo lectura: cuotas con estatus pagado, sin atribuirles un periodo de pago.
        $pagadasQuery = CobranzaCuota::query()
            ->join('polizas', 'cobranza_cuotas.poliza_id', '=', 'polizas.id')
            ->where('cobranza_cuotas.workspace_id', $workspaceId)
            ->where('polizas.workspace_id', $workspaceId)
            ->where('cobranza_cuotas.estatus', 'pagado');
        if ($request->filled('agente_id')) {
            $agenteId = $request->integer('agente_id');
            $pagadasQuery->where(function ($q) use ($agenteId, $workspaceId) {
                $q->where('polizas.agente_id', $agenteId)
                    ->orWhereExists(function ($sub) use ($agenteId, $workspaceId) {
                        $sub->select(DB::raw(1))->from('agente_workspaces')
                            ->whereColumn('agente_workspaces.id', 'polizas.agente_id')
                            ->where('agente_workspaces.workspace_id', $workspaceId)
                            ->where('agente_workspaces.agente_id', $agenteId);
                    });
            });
        }
        foreach (['aseguradora_id', 'ramo_id'] as $filter) {
            if ($request->filled($filter)) {
                $pagadasQuery->where('polizas.' . $filter, $request->integer($filter));
            }
        }
        $pagadasPorMoneda = $pagadasQuery->select('polizas.moneda')
            ->selectRaw('COUNT(cobranza_cuotas.id) as cantidad, SUM(cobranza_cuotas.monto) as monto')
            ->groupBy('polizas.moneda')->orderBy('polizas.moneda')->get();
        $resumenPagadas = [
            'cantidad' => (int) $pagadasPorMoneda->sum('cantidad'),
            'montos_por_moneda' => (object) $pagadasPorMoneda->mapWithKeys(fn ($row) => [
                $row->moneda ?: 'Sin moneda' => round((float) $row->monto, 2),
            ])->all(),
        ];

        // Hasta 5 casos prioritarios ordenados por urgencia (vencidas con mayor atraso primero)
        $casosPrioritarios = (clone $buildBaseQuery())
            ->select('cobranza_cuotas.*')
            ->with(['poliza.contratante'])
            ->orderBy('cobranza_cuotas.fecha_programada', 'asc')
            ->limit(5)
            ->get()
            ->map(function (CobranzaCuota $cuota) use ($today) {
                $fecha = $cuota->fecha_programada ? Carbon::parse($cuota->fecha_programada)->startOfDay() : null;
                $diasAtraso = ($fecha && $fecha->lt($today)) ? (int) $fecha->diffInDays($today) : 0;

                return [
                    'id' => $cuota->id,
                    'cuota_id' => $cuota->id,
                    'cliente' => $cuota->poliza?->contratante?->nombre ?? 'Sin cliente',
                    'poliza' => $cuota->poliza?->numero_poliza ?? '—',
                    'poliza_id' => $cuota->poliza_id,
                    'fecha' => $fecha ? $fecha->toDateString() : null,
                    'monto' => (float) $cuota->monto,
                    'moneda' => $cuota->poliza?->moneda ?: 'MXN',
                    'dias_atraso' => $diasAtraso,
                ];
            })
            ->values();

        return response()->json([
            'filtros_aplicados' => [
                'agente_id' => $request->filled('agente_id') ? $request->integer('agente_id') : null,
                'aseguradora_id' => $request->filled('aseguradora_id') ? $request->integer('aseguradora_id') : null,
                'ramo_id' => $request->filled('ramo_id') ? $request->integer('ramo_id') : null,
            ],
            'fecha_corte' => $todayStr,
            'totales_generales' => $totalesGenerales,
            'vencidas' => $resumenVencidas,
            'pagadas' => $resumenPagadas,
            'hoy' => $resumenHoy,
            'proximos_7_dias' => $resumenProximos,
            'casos_prioritarios' => $casosPrioritarios,
        ]);
    }
}
