<?php

namespace App\Http\Controllers\Api;

use App\Models\CobranzaCuota;
use App\Models\Contratante;
use App\Models\Poliza;
use App\Models\Tramite;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseApiController
{
    public function summary(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::DASHBOARD_VIEW);
        $workspaceId = $this->resolveWorkspaceId($request);
        $today = now()->startOfDay();

        $clientesTotal = Contratante::query()
            ->where('workspace_id', $workspaceId)
            ->count();

        $polizasTotal = Poliza::query()
            ->where('workspace_id', $workspaceId)
            ->count();

        $polizasVencidas = Poliza::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('fin_vigencia', '<', $today)
            ->count();

        $polizasActivas = Poliza::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('inicio_vigencia', '<=', $today)
            ->whereDate('fin_vigencia', '>=', $today)
            ->count();

        $polizasPorVencer = Poliza::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('fin_vigencia', '>=', $today)
            ->whereDate('fin_vigencia', '<=', $today->copy()->addDays(30))
            ->count();

        $inicioMes = $today->copy()->startOfMonth();
        $finMes = $today->copy()->endOfMonth();

        $primaMensual = (float) Poliza::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$inicioMes, $finMes])
            ->sum('prima_total');

        $tasaRenovacion = $polizasTotal > 0
            ? round(($polizasActivas / $polizasTotal) * 100, 2)
            : 0.0;

        $monthsBack = max(1, min(12, $request->integer('months', 6)));
        $startSeries = $today->copy()->startOfMonth()->subMonths($monthsBack - 1);

        $rawVentas = Poliza::query()
            ->selectRaw('YEAR(created_at) as y, MONTH(created_at) as m, COALESCE(SUM(prima_total), 0) as total')
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$startSeries, $today->copy()->endOfMonth()])
            ->groupBy('y', 'm')
            ->orderBy('y')
            ->orderBy('m')
            ->get();

        $ventasByMonth = [];
        foreach ($rawVentas as $row) {
            $key = sprintf('%04d-%02d', $row->y, $row->m);
            $ventasByMonth[$key] = (float) $row->total;
        }

        $ventasMensuales = [];
        $cursor = $startSeries->copy();
        while ($cursor <= $today->copy()->startOfMonth()) {
            $key = $cursor->format('Y-m');
            $ventasMensuales[] = [
                'name' => ucfirst(Carbon::parse($cursor)->locale('es')->isoFormat('MMM')),
                'ventas' => $ventasByMonth[$key] ?? 0,
            ];
            $cursor->addMonth();
        }

        $cobrosPendientes = CobranzaCuota::query()
            ->with(['poliza.contratante'])
            ->where('workspace_id', $workspaceId)
            ->whereIn('estatus', ['pendiente', 'vencido'])
            ->orderBy('fecha_programada')
            ->limit(5)
            ->get()
            ->map(function (CobranzaCuota $cuota) use ($today) {
                $fecha = $cuota->fecha_programada ? Carbon::parse($cuota->fecha_programada)->startOfDay() : null;
                $isOverdue = $fecha ? $fecha->lt($today) : false;

                return [
                    'id' => $cuota->id,
                    'name' => $cuota->poliza?->contratante?->nombre ?? 'Sin contratante',
                    'detail' => ($cuota->poliza?->numero_poliza ? '#'.$cuota->poliza->numero_poliza.' - ' : '').'$'.number_format((float) $cuota->monto, 2),
                    'time' => $isOverdue ? 'Vencido' : ($fecha ? $fecha->locale('es')->isoFormat('DD MMM') : 'Pendiente'),
                    'type' => $isOverdue ? 'mora' : 'cobro',
                ];
            })
            ->values();

        $accionesUrgentes = collect();

        $polizasUrgentes = Poliza::query()
            ->with('contratante')
            ->where('workspace_id', $workspaceId)
            ->whereDate('fin_vigencia', '>=', $today)
            ->whereDate('fin_vigencia', '<=', $today->copy()->addDays(30))
            ->orderBy('fin_vigencia')
            ->limit(3)
            ->get()
            ->map(function (Poliza $poliza) use ($today) {
                $dias = Carbon::parse($poliza->fin_vigencia)->startOfDay()->diffInDays($today);

                return [
                    'id' => 'poliza-'.$poliza->id,
                    'title' => 'Renovar Póliza #'.$poliza->numero_poliza,
                    'client' => $poliza->contratante?->nombre ?? 'Sin contratante',
                    'tag' => $dias.' días',
                    'isAlert' => $dias <= 7,
                ];
            });

        $tramitesUrgentes = Tramite::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) {
                $q->whereRaw('LOWER(semaforo) = ?', ['rojo'])
                    ->orWhereRaw('LOWER(etapa) like ?', ['%urgente%']);
            })
            ->orderByDesc('fecha_ultima_modificacion')
            ->limit(3)
            ->get()
            ->map(function (Tramite $tramite) {
                return [
                    'id' => 'tramite-'.$tramite->id,
                    'title' => 'Trámite '.($tramite->folio ?: '#'.$tramite->id),
                    'client' => $tramite->nombre_agente ?: 'Sin agente',
                    'tag' => 'Urgente',
                    'isAlert' => true,
                ];
            });

        $accionesUrgentes = $accionesUrgentes
            ->concat($polizasUrgentes)
            ->concat($tramitesUrgentes)
            ->take(5)
            ->values();

        $metaObjetivo = [
            'current' => $primaMensual,
            'target' => (float) ($request->input('target') ?? 25000),
        ];
        $metaObjetivo['progress_pct'] = $metaObjetivo['target'] > 0
            ? min(100, round(($metaObjetivo['current'] / $metaObjetivo['target']) * 100, 2))
            : 0;

        return response()->json([
            'kpis' => [
                'clientes_total' => $clientesTotal,
                'polizas_vendidas' => $polizasTotal,
                'prima_mensual' => $primaMensual,
                'tasa_renovacion' => $tasaRenovacion,
                'polizas_vencidas' => $polizasVencidas,
            ],
            'charts' => [
                'distribucion_polizas' => [
                    ['name' => 'Activas', 'value' => $polizasActivas],
                    ['name' => 'Vencidas', 'value' => $polizasVencidas],
                    ['name' => 'Por vencer', 'value' => $polizasPorVencer],
                ],
                'ventas_mensuales' => $ventasMensuales,
            ],
            'operativa' => [
                'cobros_pendientes' => $cobrosPendientes,
                'acciones_urgentes' => $accionesUrgentes,
            ],
            'objetivo' => $metaObjetivo,
            'workspace_id' => $workspaceId,
        ]);
    }
}
