<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // API solo con Bearer token (sin sesión/CSRF en rutas api).
        // Si el front usa cookies Sanctum en el mismo dominio, vuelve a añadir EnsureFrontendRequestsAreStateful.

        // Alias de middleware
        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'workspace.not_locked' => \App\Http\Middleware\EnsureWorkspaceNotLocked::class,
            'permission' => \App\Http\Middleware\EnsureWorkspacePermission::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | AQUÍ LA MAGIA ✨
        |--------------------------------------------------------------------------
        | Le decimos a Laravel que NO valide el CSRF en estas rutas
        | para que Postman pueda hacer peticiones sin enviar el token.
        |
        */

        $middleware->validateCsrfTokens(except: [
            'login',
            'register',
            'api/login',
            'api/register',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $model = class_basename($e->getModel());
            $message = match ($model) {
                'CobranzaCuota' => 'La cuota de cobranza no existe o fue eliminada.',
                default => 'Recurso no encontrado.',
            };

            return response()->json(['message' => $message], 404);
        });
    })->create();
