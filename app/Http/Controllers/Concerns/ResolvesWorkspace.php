<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Authorization\WorkspaceAuthorizationService;
use Illuminate\Http\Request;

/**
 * Resuelve el workspace activo del usuario y valida acceso multi-tenant.
 */
trait ResolvesWorkspace
{
    protected function resolveWorkspaceId(Request $request, ?int $requestedWorkspaceId = null): int
    {
        /** @var User $user */
        $user = $request->user();
        $activeWorkspaceId = $user->current_workspace_id;

        if (empty($activeWorkspaceId)) {
            abort(response()->json([
                'message' => 'No hay workspace activo para el usuario.',
            ], 422));
        }

        $hasAccess = $user->workspaces()
            ->where('workspaces.id', $activeWorkspaceId)
            ->exists();

        if (! $hasAccess) {
            abort(response()->json([
                'message' => 'El workspace activo no pertenece al usuario.',
            ], 403));
        }

        if ($requestedWorkspaceId !== null && (int) $requestedWorkspaceId !== 0) {
            if ((int) $requestedWorkspaceId !== (int) $activeWorkspaceId) {
                abort(response()->json([
                    'message' => 'El workspace enviado no coincide con el workspace activo.',
                ], 422));
            }
        }

        return (int) $activeWorkspaceId;
    }

    protected function abortIfOutOfWorkspace(Request $request, int $resourceWorkspaceId): void
    {
        $workspaceId = $this->resolveWorkspaceId($request);

        if ((int) $workspaceId !== (int) $resourceWorkspaceId) {
            abort(response()->json([
                'message' => 'No tienes acceso a recursos de otro workspace.',
            ], 403));
        }
    }

    protected function requirePermission(Request $request, string $permission): void
    {
        $workspaceId = $this->resolveWorkspaceId($request);
        app(WorkspaceAuthorizationService::class)->authorize($request->user(), $workspaceId, $permission);
    }
}
