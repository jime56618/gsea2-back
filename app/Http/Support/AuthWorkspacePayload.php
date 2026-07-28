<?php

namespace App\Http\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Services\Subscription\SubscriptionService;

/**
 * Arma la respuesta de usuario + workspace activo + suscripción + permisos.
 */
class AuthWorkspacePayload
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private WorkspaceAuthorizationService $authorization,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $user->load(['workspaces.subscription.plan', 'workspaces.plan', 'currentWorkspace.subscription.plan', 'currentWorkspace.plan']);

        $workspace = $user->currentWorkspace;
        $membership = null;
        $permissions = [];

        if ($workspace) {
            $membership = $this->authorization->membership($user, $workspace->id);
            $permissions = $this->authorization->permissionNamesForUser($user, $workspace->id);
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'current_workspace_id' => $user->current_workspace_id,
            ],
            'workspaces' => $user->workspaces->map(fn (Workspace $ws) => $this->workspaceSummary($ws)),
            'current_workspace' => $workspace ? $this->workspaceDetail($workspace, $membership, $permissions) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function workspaceSummary(Workspace $ws): array
    {
        return [
            'id' => $ws->id,
            'nombre' => $ws->nombre,
            'slug' => $ws->slug,
            'subscription' => $this->subscriptions->toApiArray($ws),
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function workspaceDetail(Workspace $ws, $membership, array $permissions): array
    {
        $role = $membership?->role;

        return [
            'id' => $ws->id,
            'nombre' => $ws->nombre,
            'slug' => $ws->slug,
            'owner_id' => $ws->owner_id,
            'subscription' => $this->subscriptions->toApiArray($ws),
            'membership' => $membership ? [
                'role' => $role ? [
                    'id' => $role->id,
                    'nombre' => $role->nombre,
                    'slug' => $role->slug,
                    'is_system' => (bool) $role->is_system,
                ] : null,
                'permissions' => $permissions,
            ] : null,
        ];
    }
}
