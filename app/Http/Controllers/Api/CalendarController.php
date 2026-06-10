<?php

namespace App\Http\Controllers\Api;

use App\Models\CobranzaCuota;
use App\Support\WorkspacePermissions;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends BaseApiController
{
    /**
     * Cuotas programadas en un rango (calendario / seguimiento de cobranza).
     */
    public function cobranzaCuotas(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CALENDAR_VIEW);
        $requestedWs = $request->filled('workspace_id') ? $request->integer('workspace_id') : null;
        $workspaceId = $this->resolveWorkspaceId($request, $requestedWs);

        if ($request->filled('year') && $request->filled('month')) {
            $start = Carbon::createFromDate(
                $request->integer('year'),
                $request->integer('month'),
                1
            )->startOfDay();
            $end = $start->copy()->endOfMonth();
        } else {
            $start = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
            $end = $request->date('to')?->endOfDay() ?? now()->endOfMonth();
        }

        $query = CobranzaCuota::query()
            ->with(['poliza.contratante', 'poliza.aseguradora', 'poliza.agenteWorkspace.agente'])
            ->where('workspace_id', $workspaceId)
            ->whereBetween('fecha_programada', [$start->toDateString(), $end->toDateString()])
            ->orderBy('fecha_programada')
            ->orderBy('numero_cuota');

        if ($request->filled('estatus')) {
            $query->where('estatus', $request->string('estatus'));
        }

        return $query->get();
    }
}
