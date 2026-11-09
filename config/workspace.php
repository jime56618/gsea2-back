<?php

/**
 * Configuración multi-tenant (promotoría = workspace).
 * Trial y bloqueo aplican por workspace, no por usuario global.
 */
return [

    /** Días de prueba al crear una promotoría nueva. */
    'trial_days' => (int) env('WORKSPACE_TRIAL_DAYS', 14),

    /** Días de tolerancia después del vencimiento de una suscripción pagada. */
    'payment_grace_days' => (int) env('WORKSPACE_PAYMENT_GRACE_DAYS', 3),

    /**
     * Rutas API (sin prefijo "api/") permitidas con workspace bloqueado.
     * El usuario puede ver estado y "pagar" aunque el trial haya vencido.
     */
    'locked_allowed_route_names' => [
        'user.me',
        'user.workspace',
        'logout',
        'api.logout',
        'billing.status',
        'billing.activate',
        'billing.plans',
        'billing.preview',
        'billing.change',
        'billing.checkout',
        'billing.checkout.verify',
        'invitations.accept',
        'invitations.show-by-token',
    ],

];
