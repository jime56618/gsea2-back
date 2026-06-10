<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Services\Authorization\WorkspaceAuthorizationService;
use Illuminate\Http\Request;

/**
 * Listado de roles asignables (sistema + custom del workspace activo).
 * La edición de permisos va en WorkspaceRoleController.
 */
class RoleController extends BaseApiController
{
    public function __construct(private WorkspaceAuthorizationService $authorization) {}

    public function index(Request $request)
    {
        $workspaceId = $this->resolveWorkspaceId($request);

        $roles = $this->authorization->assignableRolesForWorkspace($workspaceId)
            ->load('permissions');

        return response()->json(['data' => $roles]);
    }

    public function show(Request $request, Role $role)
    {
        $workspaceId = $this->resolveWorkspaceId($request);

        if (! $this->authorization->roleBelongsToWorkspace($role, $workspaceId)) {
            abort(response()->json(['message' => 'Rol no disponible en este workspace.'], 403));
        }

        return $role->load('permissions');
    }
}
