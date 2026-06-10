<?php

namespace App\Http\Middleware;

use App\Services\Authorization\WorkspaceAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware: permission:polizas.view */
class EnsureWorkspacePermission
{
    public function __construct(private WorkspaceAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $workspaceId = $user?->current_workspace_id;

        if (! $user || ! $workspaceId) {
            return response()->json(['message' => 'Workspace activo requerido.'], 422);
        }

        $this->authorization->authorize($user, (int) $workspaceId, $permission);

        return $next($request);
    }
}
