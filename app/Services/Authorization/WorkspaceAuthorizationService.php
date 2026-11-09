<?php

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Comprueba permisos del usuario dentro de un workspace (promotoría).
 */
class WorkspaceAuthorizationService
{
    public function membership(User $user, int $workspaceId): ?WorkspaceUser
    {
        $cacheKey = '__ws_membership_' . $user->id . '_' . $workspaceId;
        $request = request();
        if ($request && $request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->with(['role.permissions'])
            ->first();

        if ($request) {
            $request->attributes->set($cacheKey, $membership);
        }

        return $membership;
    }

    public function hasPermission(User $user, int $workspaceId, string $permission): bool
    {
        $membership = $this->membership($user, $workspaceId);

        if (! $membership?->role) {
            return false;
        }

        return $membership->role->hasPermission($permission);
    }

    public function authorize(User $user, int $workspaceId, string $permission): void
    {
        if (! $this->hasPermission($user, $workspaceId, $permission)) {
            throw new HttpResponseException(response()->json([
                'message' => 'No tienes permiso para esta acción.',
                'code' => 'forbidden',
                'permission' => $permission,
            ], 403));
        }
    }

    /** @return list<string> */
    public function permissionNamesForUser(User $user, int $workspaceId): array
    {
        $membership = $this->membership($user, $workspaceId);

        if (! $membership?->role) {
            return [];
        }

        return $membership->role->permissions->pluck('name')->sort()->values()->all();
    }

    /**
     * Roles asignables en un workspace: plantillas de sistema + custom del tenant.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    public function assignableRolesForWorkspace(int $workspaceId)
    {
        return Role::query()
            ->where(function ($q) use ($workspaceId) {
                $q->whereNull('workspace_id')->where('is_system', true);
                $q->orWhere('workspace_id', $workspaceId);
            })
            ->orderBy('is_system', 'desc')
            ->orderBy('nombre')
            ->get();
    }

    public function roleBelongsToWorkspace(Role $role, int $workspaceId): bool
    {
        if ($role->is_system && $role->workspace_id === null) {
            return true;
        }

        return (int) $role->workspace_id === $workspaceId;
    }

    public function canManageRoles(User $user, Workspace $workspace): bool
    {
        return $this->hasPermission($user, $workspace->id, 'roles.manage');
    }
}
