<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AgentePromotoriaController;
use App\Http\Controllers\Api\AseguradoraController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CobranzaCuotaController;
use App\Http\Controllers\Api\ContratanteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvitationAcceptController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\PermissionCatalogController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PolizaController;
use App\Http\Controllers\Api\PolizaDocumentoController;
use App\Http\Controllers\Api\PolizaVehiculoController;
use App\Http\Controllers\Api\RamoController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SubramoController;
use App\Http\Controllers\Api\TramiteController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceRoleController;
use App\Http\Controllers\Api\WorkspaceUserController;
use App\Http\Controllers\Api\GoogleCalendarIntegrationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Invitaciones (público)
Route::get('/invitations/token/{token}', [InvitationAcceptController::class, 'showByToken'])
    ->name('invitations.show-by-token');
Route::post('/invitations/accept', [InvitationAcceptController::class, 'accept'])
    ->name('invitations.accept');

Route::get('/integrations/google-calendar/callback', [GoogleCalendarIntegrationController::class, 'callback']);    

Route::middleware(['auth:sanctum'])->group(function () {

    // Siempre disponibles (incluso con workspace bloqueado)
    Route::get('/user', [AuthController::class, 'me'])->name('user.me');
    Route::post('/me/workspace', [AuthController::class, 'setCurrentWorkspace'])->name('user.workspace');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/integrations/google-calendar/status', [GoogleCalendarIntegrationController::class, 'status']);
    Route::get('/integrations/google-calendar/redirect', [GoogleCalendarIntegrationController::class, 'redirect']);
    Route::delete('/integrations/google-calendar/disconnect', [GoogleCalendarIntegrationController::class, 'disconnect']);

    Route::get('/workspaces/{workspace}/billing', [BillingController::class, 'status'])
        ->name('billing.status');
    Route::post('/workspaces/{workspace}/billing/activate', [BillingController::class, 'activate'])
        ->name('billing.activate');

    Route::middleware(['workspace.not_locked'])->group(function () {

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        Route::get('/permissions', [PermissionCatalogController::class, 'index']);

        Route::apiResource('users', UserController::class);
        Route::apiResource('workspaces', WorkspaceController::class);
        Route::apiResource('invitations', InvitationController::class);
        Route::apiResource('workspace_users', WorkspaceUserController::class)
            ->parameters(['workspace_users' => 'workspace_user']);

        // Roles y editor de permisos
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::get('/workspaces/{workspace}/roles', [WorkspaceRoleController::class, 'index']);
        Route::post('/workspaces/{workspace}/roles', [WorkspaceRoleController::class, 'store']);
        Route::put('/roles/{role}/permissions', [WorkspaceRoleController::class, 'syncPermissions']);
        Route::patch('/roles/{role}', [WorkspaceRoleController::class, 'update']);
        Route::delete('/roles/{role}', [WorkspaceRoleController::class, 'destroy']);

        Route::apiResource('aseguradoras', AseguradoraController::class);
        Route::apiResource('planes', PlanController::class);
        Route::apiResource('ramos', RamoController::class);
        Route::apiResource('subramos', SubramoController::class);

        Route::apiResource('contratantes', ContratanteController::class);

        Route::apiResource('agentes', AgenteController::class);
        Route::apiResource('agentes_workspace', AgentePromotoriaController::class)
            ->parameters(['agentes_workspace' => 'agente_promotoria']);
        Route::apiResource('agentes_promotoria', AgentePromotoriaController::class)
            ->parameters(['agentes_promotoria' => 'agente_promotoria']);

        Route::apiResource('tramites', TramiteController::class);

        Route::get('calendar/cobranza-cuotas', [CalendarController::class, 'cobranzaCuotas']);
        Route::apiResource('cobranza_cuotas', CobranzaCuotaController::class)
            ->parameters(['cobranza_cuotas' => 'cobranza_cuota']);

        Route::apiResource('polizas', PolizaController::class);
        Route::apiResource('poliza_vehiculos', PolizaVehiculoController::class)
            ->parameters(['poliza_vehiculos' => 'poliza_vehiculo']);
        Route::apiResource('poliza_documentos', PolizaDocumentoController::class);

        Route::get('calendar/events', [CalendarController::class, 'events']);
        Route::post('calendar/events', [CalendarController::class, 'store']);
        Route::put('calendar/events/{event}', [CalendarController::class, 'update']);
        Route::delete('calendar/events/{event}', [CalendarController::class, 'destroy']);
    });
});
