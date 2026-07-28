<?php

return [

    'enabled' => env('WHATSAPP_ENABLED', false),

    /**
     * Proveedor: ycloud (recomendado en producción) | twilio (sandbox/demo).
     */
    'provider' => env('WHATSAPP_PROVIDER', 'ycloud'),

    /** Días antes del pago en los que se envía recordatorio (7, 3, 1 y el día del pago). */
    'reminder_offsets_days' => array_map(
        'intval',
        array_filter(explode(',', env('WHATSAPP_REMINDER_OFFSETS', '7,3,1,0')))
    ),

    /** Enviar mensaje de alta al crear/regenerar póliza (si hay teléfono). */
    'send_alta_on_poliza_sync' => env('WHATSAPP_SEND_ALTA_ON_POLIZA', true),

    'timezone' => env('WHATSAPP_TIMEZONE', 'America/Mexico_City'),

    'ycloud' => [
        'api_key' => env('YCLOUD_API_KEY'),
        'from' => env('YCLOUD_FROM'),
        'base_url' => env('YCLOUD_BASE_URL', 'https://api.ycloud.com/v2'),
        'use_send_directly' => env('YCLOUD_SEND_DIRECTLY', false),
        'template_language' => env('YCLOUD_TEMPLATE_LANG', 'es_MX'),
        /**
         * Nombres de plantillas aprobadas en Meta (vía YCloud).
         * Debe coincidir el orden de variables con WhatsAppGateway::templateParams.
         */
        'templates' => [
            'alta' => env('YCLOUD_TEMPLATE_ALTA', 'g_pruebas'),
            'recordatorio' => env('YCLOUD_TEMPLATE_RECORDATORIO', 'gsea_recordatorio_pago'),
            'dia_pago' => env('YCLOUD_TEMPLATE_DIA_PAGO', 'gsea_dia_pago'),
            'test' => env('YCLOUD_TEMPLATE_TEST'),
        ],
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
    ],

];
