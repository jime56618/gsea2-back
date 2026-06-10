<?php

namespace App\Http\Controllers\Api;

use App\Actions\RegisterWorkspaceForOwner;
use App\Models\Workspace;
use App\Support\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends BaseApiController
{
    public function __construct(private RegisterWorkspaceForOwner $registerWorkspace) {}

    public function index(Request $request)
    {
        return $request->user()
            ->workspaces()
            ->with(['plan', 'owner', 'subscription.plan'])
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 15));
    }

    /**
     * Crear otra promotoría (nuevo trial de 14 días para ese workspace).
     */
    public function store(Request $request)
    {
        $this->requirePermission($request, WorkspacePermissions::WORKSPACES_MANAGE);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:workspaces,slug',
        ]);

        $user = $request->user();

        $workspace = $this->registerWorkspace->execute($user, $data['nombre']);

        if (! empty($data['slug'])) {
            $workspace->update(['slug' => $data['slug']]);
        }

        return response()->json($workspace->load(['plan', 'owner', 'subscription.plan']), 201);
    }

    public function show(Request $request, Workspace $workspace)
    {
        $this->ensureUserBelongsToWorkspace($request, $workspace);

        return $workspace->load(['plan', 'owner', 'subscription.plan', 'users']);
    }

    public function update(Request $request, Workspace $workspace)
    {
        $this->ensureUserBelongsToWorkspace($request, $workspace);
        $this->requirePermission($request, WorkspacePermissions::WORKSPACES_MANAGE);

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:workspaces,slug,'.$workspace->id,
        ]);

        $workspace->update($data);

        return $workspace->fresh()->load(['plan', 'owner', 'subscription.plan']);
    }

    public function destroy(Request $request, Workspace $workspace)
    {
        $this->ensureUserBelongsToWorkspace($request, $workspace);

        if ((int) $workspace->owner_id !== (int) $request->user()->id) {
            abort(response()->json(['message' => 'Solo el propietario puede eliminar la promotoría.'], 403));
        }

        $workspace->delete();

        return response()->noContent();
    }

    private function ensureUserBelongsToWorkspace(Request $request, Workspace $workspace): void
    {
        $belongs = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists();

        if (! $belongs) {
            abort(response()->json(['message' => 'No tienes acceso a esta promotoría.'], 403));
        }
    }
}
