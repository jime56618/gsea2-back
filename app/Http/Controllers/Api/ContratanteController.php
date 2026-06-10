<?php

namespace App\Http\Controllers\Api;

use App\Models\Contratante;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;

class ContratanteController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CONTRATANTES_VIEW);
        $requestedWs = $request->filled('workspace_id') ? $request->integer('workspace_id') : null;
        $workspaceId = $this->resolveWorkspaceId($request, $requestedWs);

        return Contratante::query()
            ->where('workspace_id', $workspaceId)
            ->withCount('polizas')
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 15));
    }

    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::CONTRATANTES_MANAGE);
        $data = $request->validate([
            'workspace_id' => 'sometimes|exists:workspaces,id',
            'nombre' => 'required|string|max:255',
            'rfc' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'direccion' => 'nullable|string',
        ]);

        $workspaceId = $this->resolveWorkspaceId($request, $data['workspace_id'] ?? null);

        $contratante = Contratante::create([
            'workspace_id' => $workspaceId,
            'nombre' => $data['nombre'],
            'rfc' => $data['rfc'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'] ?? null,
            'direccion' => $data['direccion'] ?? null,
        ]);

        return response()->json($contratante->loadCount('polizas'), 201);
    }

    public function show(Request $request, Contratante $contratante)
    {
        $this->requirePermission($request, WorkspacePermissions::CONTRATANTES_VIEW);
        $this->abortIfOutOfWorkspace($request, $contratante->workspace_id);

        return $contratante->load(['workspace', 'polizas']);
    }

    public function update(Request $request, Contratante $contratante)
    {
        $this->requirePermission($request, WorkspacePermissions::CONTRATANTES_MANAGE);
        $activeWorkspaceId = $this->resolveWorkspaceId($request);
        $this->abortIfOutOfWorkspace($request, $contratante->workspace_id);

        $data = $request->validate([
            'workspace_id' => ['sometimes', 'required', 'exists:workspaces,id'],
            'nombre' => 'sometimes|required|string|max:255',
            'rfc' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'direccion' => 'nullable|string',
        ]);

        if (array_key_exists('workspace_id', $data) && (int) $data['workspace_id'] !== (int) $activeWorkspaceId) {
            return response()->json([
                'message' => 'El workspace enviado no coincide con el workspace activo.',
            ], 422);
        }

        $contratante->update(collect($data)->except('workspace_id')->all());

        return $contratante->fresh()->loadCount('polizas');
    }

    public function destroy(Request $request, Contratante $contratante)
    {
        $this->requirePermission($request, WorkspacePermissions::CONTRATANTES_MANAGE);
        $this->abortIfOutOfWorkspace($request, $contratante->workspace_id);
        $contratante->delete();

        return response()->noContent();
    }
}
