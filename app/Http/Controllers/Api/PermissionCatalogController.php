<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\WorkspacePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lista permisos disponibles para el editor de roles (solo lectura). */
class PermissionCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::query()->orderBy('group')->orderBy('name')->get();

        return response()->json([
            'catalog' => WorkspacePermissions::catalog(),
            'permissions' => $permissions,
        ]);
    }
}
