<?php

namespace App\Http\Controllers\Api;

use App\Models\Tramite;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TramiteController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::TRAMITES_VIEW);
        $requestedWs = $request->filled('workspace_id') ? $request->integer('workspace_id') : null;
        $workspaceId = $this->resolveWorkspaceId($request, $requestedWs);
        $filters = $request->validate(['estado' => 'sometimes|in:en_atencion,finalizado,cancelado']);

        $list = Tramite::query()
            ->where('workspace_id', $workspaceId)
            ->when(isset($filters['estado']), function ($query) use ($filters) {
                if (Schema::hasColumn('tramites', 'estado')) {
                    $query->where('estado', $filters['estado']);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('fecha_ultima_modificacion')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json(array_merge($list->toArray(), [
            'estado_disponible' => Schema::hasColumn('tramites', 'estado'),
        ]));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::TRAMITES_MANAGE);
        $data = $request->validate([
            'workspace_id' => 'sometimes|exists:workspaces,id',
            'folio' => 'nullable|string|max:100',
            'tipo' => 'nullable|string|max:100',
            'tipo_tramite' => 'nullable|string|max:100',
            'etapa' => 'nullable|string|max:50',
            'estado' => 'sometimes|required|in:en_atencion,finalizado,cancelado',
            'nombre_agente' => 'nullable|string|max:255',
            'ramo' => 'nullable|string|max:100',
            'subramo' => 'nullable|string|max:100',
            'poliza_referencia' => 'nullable|string|max:100',
            'fecha_alta' => 'nullable|date',
            'observaciones' => 'nullable|string|max:555',
            'aseguradora' => 'nullable|string|max:555',
            'dias_fecha_alta' => 'nullable|string|max:555',
            'dias_etapa_actual' => 'nullable|string|max:555',
            'semaforo' => 'nullable|string|max:555',
            'centro_emisor' => 'nullable|string|max:100',
        ]);

        $workspaceId = $this->resolveWorkspaceId($request, $data['workspace_id'] ?? null);
        if (Schema::hasColumn('tramites', 'estado')) {
            $data['estado'] ??= 'en_atencion';
        } elseif (array_key_exists('estado', $data)) {
            return response()->json(['message' => 'El estado requiere aplicar la migración de trámites.'], 503);
        }

        $tramite = Tramite::create(array_merge(
            collect($data)->except('workspace_id')->all(),
            [
                'workspace_id' => $workspaceId,
                'fecha_ultima_modificacion' => now(),
            ]
        ));

        return response()->json($tramite, 201);
    }

    public function show(Request $request, Tramite $tramite)
    {
        $this->requirePermission($request, WorkspacePermissions::TRAMITES_VIEW);
        $this->abortIfOutOfWorkspace($request, $tramite->workspace_id);

        return $tramite->load('workspace');
    }

    public function update(Request $request, Tramite $tramite)
    {
        $this->requirePermission($request, WorkspacePermissions::TRAMITES_MANAGE);
        $activeWorkspaceId = $this->resolveWorkspaceId($request);
        $this->abortIfOutOfWorkspace($request, $tramite->workspace_id);

        $data = $request->validate([
            'workspace_id' => 'sometimes|required|exists:workspaces,id',
            'folio' => 'nullable|string|max:100',
            'tipo' => 'nullable|string|max:100',
            'tipo_tramite' => 'nullable|string|max:100',
            'etapa' => 'nullable|string|max:50',
            'estado' => 'sometimes|required|in:en_atencion,finalizado,cancelado',
            'nombre_agente' => 'nullable|string|max:255',
            'ramo' => 'nullable|string|max:100',
            'subramo' => 'nullable|string|max:100',
            'poliza_referencia' => 'nullable|string|max:100',
            'fecha_alta' => 'nullable|date',
            'observaciones' => 'nullable|string|max:555',
            'aseguradora' => 'nullable|string|max:555',
            'dias_fecha_alta' => 'nullable|string|max:555',
            'dias_etapa_actual' => 'nullable|string|max:555',
            'semaforo' => 'nullable|string|max:555',
            'centro_emisor' => 'nullable|string|max:100',
        ]);

        if (array_key_exists('workspace_id', $data) && (int) $data['workspace_id'] !== (int) $activeWorkspaceId) {
            return response()->json([
                'message' => 'El workspace enviado no coincide con el workspace activo.',
            ], 422);
        }

        if (array_key_exists('estado', $data) && ! Schema::hasColumn('tramites', 'estado')) {
            return response()->json(['message' => 'El estado requiere aplicar la migración de trámites.'], 503);
        }

        $tramite->update(array_merge(
            collect($data)->except('workspace_id')->all(),
            ['fecha_ultima_modificacion' => now()]
        ));

        return $tramite->fresh();
    }

    public function destroy(Request $request, Tramite $tramite)
    {
        $this->requirePermission($request, WorkspacePermissions::TRAMITES_MANAGE);
        $this->abortIfOutOfWorkspace($request, $tramite->workspace_id);
        $tramite->delete();

        return response()->noContent();
    }
}
