<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\Workspace;
use App\Services\Authorization\WorkspaceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editor de roles por promotoría: CRUD roles custom + asignación de permisos.
 */
class WorkspaceRoleController extends BaseApiController
{
    public function __construct(private WorkspaceAuthorizationService $authorization) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureWorkspaceAccess($request, $workspace);
        $this->requirePermission($request, 'roles.manage');

        $roles = $this->authorization->assignableRolesForWorkspace($workspace->id)
            ->load('permissions');

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureWorkspaceAccess($request, $workspace);
        $this->requirePermission($request, 'roles.manage');

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'slug' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $slug = $data['slug'] ?? Str::slug($data['nombre']);

        $role = Role::create([
            'nombre' => $data['nombre'],
            'slug' => $slug,
            'workspace_id' => $workspace->id,
            'is_system' => false,
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['permissions'])) {
            $role->syncPermissionNames($data['permissions']);
        }

        return response()->json($role->load('permissions'), 201);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $workspaceId = $this->resolveWorkspaceId($request);
        $this->assertRoleInWorkspace($role, $workspaceId);
        $this->requirePermission($request, 'roles.manage');

        return response()->json($role->load('permissions'));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $workspaceId = $this->resolveWorkspaceId($request);
        $this->assertRoleInWorkspace($role, $workspaceId);
        $this->requirePermission($request, 'roles.manage');

        if ($role->is_system) {
            return response()->json(['message' => 'Los roles de sistema no se editan. Crea un rol personalizado.'], 422);
        }

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'string', 'max:64', Rule::unique('roles', 'slug')->where('workspace_id', $workspaceId)->ignore($role->id)],
            'description' => 'nullable|string|max:500',
        ]);

        $role->update($data);

        return response()->json($role->fresh()->load('permissions'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $workspaceId = $this->resolveWorkspaceId($request);
        $this->assertRoleInWorkspace($role, $workspaceId);
        $this->requirePermission($request, 'roles.manage');

        if ($role->is_system) {
            return response()->json(['message' => 'No se pueden eliminar roles de sistema.'], 422);
        }

        if ($role->workspaceUsers()->exists()) {
            return response()->json(['message' => 'El rol tiene usuarios asignados.'], 422);
        }

        $role->delete();

        return response()->noContent();
    }

    /** PUT /roles/{role}/permissions — matriz del editor */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $workspaceId = $this->resolveWorkspaceId($request);
        $this->assertRoleInWorkspace($role, $workspaceId);
        $this->requirePermission($request, 'roles.manage');

        if ($role->is_system && $role->slug === 'admin') {
            return response()->json(['message' => 'No se pueden modificar permisos del rol Administrador.'], 422);
        }

        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->syncPermissionNames($data['permissions']);

        return response()->json($role->fresh()->load('permissions'));
    }

    private function ensureWorkspaceAccess(Request $request, Workspace $workspace): void
    {
        $activeId = $this->resolveWorkspaceId($request);

        if ((int) $activeId !== (int) $workspace->id) {
            abort(response()->json(['message' => 'Solo puedes gestionar roles del workspace activo.'], 422));
        }

        $belongs = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists();

        if (! $belongs) {
            abort(response()->json(['message' => 'Sin acceso al workspace.'], 403));
        }
    }

    private function assertRoleInWorkspace(Role $role, int $workspaceId): void
    {
        if (! $this->authorization->roleBelongsToWorkspace($role, $workspaceId)) {
            abort(response()->json(['message' => 'Rol no disponible en este workspace.'], 403));
        }
    }
}
