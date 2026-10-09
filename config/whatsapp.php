<?php

/**
 * Parsea lista de días desde .env (ej. "7,3,1,0").
 * IMPORTANTE: no usar array_filter() sin callback — en PHP elimina el "0"
 * y se pierde el aviso del día del pago / vencimiento.
 *
 * @return list<int>
 */
$parseDayOffsets = static function (?string $raw, string $default): array {
    $parts = explode(',', $raw !== null && $raw !== '' ? $raw : $default);

    $days = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $days[] = (int) $part;
    }

    return $days;
};

return [

    'enabled' => env('WHATSAPP_ENABLED', false),

    /**
     * Proveedor: ycloud (recomendado en producción) | twilio (sandbox/demo).
     */
    'provider' => env('WHATSAPP_PROVIDER', 'ycloud'),

    /** Días antes del pago en los que se envía recordatorio (7, 3, 1 y el día del pago). */
    'reminder_offsets_days' => $parseDayOffsets(
        env('WHATSAPP_REMINDER_OFFSETS'),
        '7,3,1,0'
    ),

    /**
     * Días antes de fin_vigencia para avisar que la póliza está por vencer.
     * Default: 30, 15, 7, 1 y el día del vencimiento (0).
     */
    'vigencia_offsets_days' => $parseDayOffsets(
        env('WHATSAPP_VIGENCIA_OFFSETS'),
        '30,15,7,1,0'
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
            /** Plantilla de vencimiento: {{1}}nombre {{2}}póliza {{3}}aseguradora {{4}}fecha {{5}}días */
            'vencimiento' => env('YCLOUD_TEMPLATE_VENCIMIENTO', 'g_pruebas2'),
            'test' => env('YCLOUD_TEMPLATE_TEST'),
        ],
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
    ],

];
