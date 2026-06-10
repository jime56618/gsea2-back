<?php

namespace App\Http\Middleware;

use App\Services\Subscription\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el uso de la app si el trial de la promotoría activa venció y no hay pago.
 * Rutas de billing y perfil siguen disponibles (config/workspace.php).
 */
class EnsureWorkspaceNotLocked
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->current_workspace_id) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $allowed = config('workspace.locked_allowed_route_names', []);

        if ($routeName && in_array($routeName, $allowed, true)) {
            return $next($request);
        }

        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return $next($request);
        }

        if ($this->subscriptions->isAccessible($workspace)) {
            return $next($request);
        }

        $payload = $this->subscriptions->toApiArray($workspace);

        return response()->json([
            'message' => 'El periodo de prueba terminó. Activa tu plan para continuar.',
            'code' => 'workspace_locked',
            'subscription' => $payload,
        ], 402);
    }
}
