<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AgentePromotoriaController;
use App\Http\Controllers\Api\AseguradoraController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CapacitacionController;
use App\Http\Controllers\Api\StripeWebhookController;
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
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook');
    
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
    Route::get('/workspaces/{workspace}/billing/plans', [BillingController::class, 'plans'])
        ->name('billing.plans');
    Route::post('/workspaces/{workspace}/billing/preview-change', [BillingController::class, 'previewChange'])
        ->name('billing.preview');
    Route::post('/workspaces/{workspace}/billing/change-plan', [BillingController::class, 'changePlan'])
        ->name('billing.change');
    Route::post('/workspaces/{workspace}/billing/activate', [BillingController::class, 'activate'])
        ->name('billing.activate');
        
    Route::post('workspaces/{workspace}/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');
    Route::post('workspaces/{workspace}/billing/checkout/verify', [BillingController::class, 'verifyCheckoutSession'])
        ->name('billing.checkout.verify');
    Route::post('workspaces/{workspace}/billing/subscription/cancel', [BillingController::class, 'cancelSubscription'])
        ->name('billing.subscription.cancel');
    Route::post('workspaces/{workspace}/billing/subscription/switch-to-manual', [BillingController::class, 'switchToManual'])
        ->name('billing.subscription.switch-to-manual');
    Route::post('workspaces/{workspace}/billing/subscription/reactivate', [BillingController::class, 'reactivateSubscription'])
        ->name('billing.subscription.reactivate');

    Route::middleware(['workspace.not_locked'])->group(function () {

        // POST de consulta: cuerpos grandes de selección, sin escrituras en base de datos.
        Route::post('/exports/{module}/count', [\App\Http\Controllers\Api\ExportController::class, 'count']);
        Route::post('/exports/{module}', [\App\Http\Controllers\Api\ExportController::class, 'download']);

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/totales', [DashboardController::class, 'totales']);
        Route::get('/dashboard/metas-comerciales', [\App\Http\Controllers\Api\MetaComercialController::class, 'index']);
        Route::post('/dashboard/metas-comerciales', [\App\Http\Controllers\Api\MetaComercialController::class, 'store']);
        Route::put('/dashboard/metas-comerciales/{id}', [\App\Http\Controllers\Api\MetaComercialController::class, 'update'])->whereNumber('id');
        Route::get('/dashboard/cobranza', [DashboardController::class, 'cobranzaSummary'])
            ->name('dashboard.cobranza');
        Route::get('/dashboard/cobranza-resumen', [DashboardController::class, 'cobranzaSummary'])
            ->name('dashboard.cobranza-resumen');

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

        Route::post('polizas/extract-pdf', [PolizaController::class, 'extractFromPdf'])
            ->name('polizas.extract-pdf');
        Route::apiResource('polizas', PolizaController::class);
        Route::apiResource('poliza_vehiculos', PolizaVehiculoController::class)
            ->parameters(['poliza_vehiculos' => 'poliza_vehiculo']);
        Route::apiResource('poliza_documentos', PolizaDocumentoController::class);

        Route::get('calendar/events', [CalendarController::class, 'events']);
        Route::post('calendar/events', [CalendarController::class, 'store']);
        Route::put('calendar/events/{event}', [CalendarController::class, 'update']);
        Route::delete('calendar/events/{event}', [CalendarController::class, 'destroy']);

        // Módulo de Capacitaciones (Academia y Aula Virtual por promotoría)
        Route::prefix('capacitaciones')->group(function () {
            // Portal del alumno / agente
            Route::get('/mis-cursos', [CapacitacionController::class, 'misCursos']);
            Route::get('/aula/cursos/{curso}', [CapacitacionController::class, 'aulaCurso']);
            Route::post('/aula/lecciones/{leccion}/progreso', [CapacitacionController::class, 'registrarProgresoLeccion']);
            Route::post('/aula/examenes/{examen}/entregar', [CapacitacionController::class, 'entregarExamen']);

            // Streaming privado de portadas, recursos y descarga de constancias PDF
            Route::get('/cursos/{curso}/portada', [CapacitacionController::class, 'streamPortada']);
            Route::get('/recursos/{recurso}/stream', [CapacitacionController::class, 'streamRecurso']);
            Route::get('/constancias/{constancia}/descargar', [CapacitacionController::class, 'descargarConstancia']);

            // Administración de Academia (propietario / rol admin / capacitaciones.manage)
            Route::prefix('admin')->group(function () {
                Route::get('/dashboard', [CapacitacionController::class, 'adminDashboard']);
                Route::get('/learners', [CapacitacionController::class, 'eligibleLearners']);
                Route::get('/agentes/{userId}/expediente', [CapacitacionController::class, 'agentExpediente'])->whereNumber('userId');
                Route::get('/constancias', [CapacitacionController::class, 'adminConstancias']);

                Route::get('/cursos', [CapacitacionController::class, 'adminIndexCursos']);
                Route::post('/cursos', [CapacitacionController::class, 'adminStoreCurso']);
                Route::get('/cursos/{curso}', [CapacitacionController::class, 'adminShowCurso']);
                Route::post('/cursos/{curso}/update', [CapacitacionController::class, 'adminUpdateCurso']);
                Route::put('/cursos/{curso}', [CapacitacionController::class, 'adminUpdateCurso']);
                Route::patch('/cursos/{curso}/estado', [CapacitacionController::class, 'adminUpdateEstadoCurso']);
                Route::post('/cursos/{curso}/duplicar', [CapacitacionController::class, 'adminDuplicateCurso']);
                Route::delete('/cursos/{curso}', [CapacitacionController::class, 'adminDestroyCurso']);
                Route::post('/cursos/{curso}/asignar', [CapacitacionController::class, 'assignCurso']);

                Route::post('/cursos/{curso}/modulos', [CapacitacionController::class, 'storeModulo']);
                Route::put('/cursos/{curso}/modulos/reordenar', [CapacitacionController::class, 'reorderModulos']);
                Route::put('/modulos/{modulo}', [CapacitacionController::class, 'updateModulo']);
                Route::delete('/modulos/{modulo}', [CapacitacionController::class, 'destroyModulo']);

                Route::post('/modulos/{modulo}/lecciones', [CapacitacionController::class, 'storeLeccion']);
                Route::put('/modulos/{modulo}/lecciones/reordenar', [CapacitacionController::class, 'reorderLecciones']);
                Route::post('/lecciones/{leccion}/update', [CapacitacionController::class, 'updateLeccion']);
                Route::put('/lecciones/{leccion}', [CapacitacionController::class, 'updateLeccion']);
                Route::delete('/lecciones/{leccion}', [CapacitacionController::class, 'destroyLeccion']);
                Route::delete('/recursos/{recurso}', [CapacitacionController::class, 'destroyRecurso']);

                Route::post('/cursos/{curso}/examenes', [CapacitacionController::class, 'saveExamen']);
                Route::delete('/examenes/{examen}', [CapacitacionController::class, 'destroyExamen']);
            });
        });
    });
});
