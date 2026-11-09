<?php

namespace App\Http\Controllers\Api;

use App\Services\Exports\ExportDataset;
use App\Services\Exports\XlsxExporter;
use App\Support\WorkspacePermissions as P;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExportController extends BaseApiController
{
    private function context(Request $request, string $module): array
    {
        abort_unless(in_array($module, ExportDataset::MODULES, true), 404);
        $this->requirePermission($request, match ($module) {
            'tramites'=>P::TRAMITES_VIEW, 'clientes'=>P::CONTRATANTES_VIEW, 'agentes'=>P::AGENTES_VIEW,
            'polizas'=>P::POLIZAS_VIEW, 'cobranza'=>P::COBRANZA_VIEW,
        });
        $data = $request->validate([
            'workspace_id'=>'required|integer|min:1', 'mode'=>'sometimes|in:selected,filtered,all',
            'ids'=>'sometimes|array|max:5000', 'ids.*'=>'integer|min:1|distinct',
            'excluded_ids'=>'sometimes|array|max:5000', 'excluded_ids.*'=>'integer|min:1|distinct',
            'selection_all'=>'sometimes|boolean',
            'filters'=>'sometimes|array:q,semaforo,estado,status,empresa,estatus,vencidas,hoy,tz_offset,poliza_id,desde,hasta',
            // ConvertEmptyStringsToNull convierte q="" en NULL antes de validar.
            'filters.q'=>'sometimes|nullable|string|max:2000', 'filters.semaforo'=>'sometimes|in:todos,verde,amarillo,rojo',
            'filters.estado'=>'sometimes|in:en_atencion,finalizado,cancelado', 'filters.status'=>'sometimes|in:todos,activo,inactivo',
            'filters.empresa'=>'sometimes|string|max:255', 'filters.estatus'=>'sometimes|in:todos,Todos,pendiente,pagado,vencido,cancelado,vencida,Vigente,No Vigente',
            'filters.vencidas'=>'sometimes|boolean','filters.hoy'=>'sometimes|date_format:Y-m-d', 'filters.tz_offset'=>'sometimes|integer|min:-840|max:840',
            'filters.poliza_id'=>'sometimes|integer|min:1', 'filters.desde'=>'sometimes|date_format:Y-m-d', 'filters.hasta'=>'sometimes|date_format:Y-m-d',
        ]);
        $ws = $this->resolveWorkspaceId($request, (int) $data['workspace_id']);
        foreach (['ids', 'excluded_ids'] as $key) if (isset($data[$key])) $data[$key] = array_map('intval', $data[$key]);
        return [$ws, $data];
    }

    public function count(Request $request, string $module, ExportDataset $dataset)
    {
        [$ws, $data] = $this->context($request, $module);
        $total = 0; $accessible = [];
        if (array_key_exists('ids', $data) && ! $data['ids']) return response()->json(['workspace_id'=>$ws, 'total'=>0, 'accessible_ids'=>[]]);
        foreach ($dataset->rows($module, $ws, $data['ids'] ?? []) as $row) {
            if ($dataset->matches($module, $row, $data['filters'] ?? [])) {
                $total++;
                if (array_key_exists('ids', $data)) $accessible[] = (int) $row['id'];
            }
        }
        return response()->json(['workspace_id'=>$ws, 'total'=>$total, 'accessible_ids'=>$accessible]);
    }

    public function download(Request $request, string $module, ExportDataset $dataset, XlsxExporter $exporter)
    {
        [$ws, $data] = $this->context($request, $module);
        $mode = $data['mode'] ?? 'filtered';
        $allSelected = $mode === 'selected' && ($data['selection_all'] ?? false);
        $ids = $mode === 'selected' && ! $allSelected ? ($data['ids'] ?? []) : [];
        if ($mode === 'selected' && ! $allSelected && ! $ids) throw ValidationException::withMessages(['ids'=>'Selecciona al menos un registro.']);
        $excluded = $allSelected ? ($data['excluded_ids'] ?? []) : [];
        // IDs enviados siempre se verifican en el dataset autorizado; no basta con el ID global.
        foreach ([$ids, $excluded] as $check) {
            if ($check && $dataset->query($module, $ws)->whereIn('r.id', $check)->count() !== count($check)) {
                throw ValidationException::withMessages(['ids'=>'La selección ya no está disponible en este workspace.']);
            }
        }
        $filters = $mode === 'all' ? [] : ($data['filters'] ?? []);
        $rows = (function () use ($dataset, $module, $ws, $ids, $excluded, $filters) {
            foreach ($dataset->rows($module, $ws, $ids) as $row) {
                if (in_array((int) $row['id'], $excluded, true)) continue;
                if (! $dataset->matches($module, $row, $filters)) {
                    if ($ids) throw ValidationException::withMessages(['ids'=>'La selección cambió. Vuelve a seleccionar los registros.']);
                    continue;
                }
                yield $row;
            }
        })();
        $path = $exporter->write($rows, $dataset->columns($module));
        return response()->download($path, 'gsea-'.$module.'-'.Carbon::today('America/Merida')->toDateString().'.xlsx', [
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control'=>'no-store, private',
        ])->deleteFileAfterSend(true);
    }
}
