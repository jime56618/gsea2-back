<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente YCloud WhatsApp Business API.
 * Docs: https://docs.ycloud.com/reference/whatsapp-message-sending-guide
 */
class YCloudWhatsAppService
{
    public function isConfigured(): bool
    {
        return filled(config('whatsapp.ycloud.api_key'))
            && filled(config('whatsapp.ycloud.from'));
    }

    /**
     * Envía texto libre (solo útil dentro de ventana de 24h o si YCloud/Meta lo permiten).
     *
     * @return array{sid: string|null, status: string}
     */
    public function sendText(string $toPhone, string $body): array
    {
        $this->assertReady();

        return $this->postMessage([
            'from' => $this->normalizeFrom(config('whatsapp.ycloud.from')),
            'to' => $this->normalizeTo($toPhone),
            'type' => 'text',
            'text' => [
                'body' => $body,
            ],
        ]);
    }

    /**
     * Envía plantilla aprobada en Meta (recomendado para recordatorios).
     *
     * @param  list<string>  $bodyParams  valores para {{1}}, {{2}}, ...
     * @return array{sid: string|null, status: string}
     */
    public function sendTemplate(string $toPhone, string $templateName, array $bodyParams = [], ?string $language = null): array
    {
        $this->assertReady();

        $components = [];
        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    array_values($bodyParams)
                ),
            ];
        }

        $payload = [
            'from' => $this->normalizeFrom(config('whatsapp.ycloud.from')),
            'to' => $this->normalizeTo($toPhone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language ?: config('whatsapp.ycloud.template_language', 'es_MX'),
                ],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        return $this->postMessage($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sid: string|null, status: string}
     */
    private function postMessage(array $payload): array
    {
        $base = rtrim((string) config('whatsapp.ycloud.base_url', 'https://api.ycloud.com/v2'), '/');
        $endpoint = config('whatsapp.ycloud.use_send_directly', false)
            ? "{$base}/whatsapp/messages/sendDirectly"
            : "{$base}/whatsapp/messages";

        $response = Http::withHeaders([
            'X-API-Key' => config('whatsapp.ycloud.api_key'),
            'Accept' => 'application/json',
        ])
            ->asJson()
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            Log::warning('YCloud WhatsApp error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'to' => $payload['to'] ?? null,
            ]);

            $msg = $response->json('message')
                ?? $response->json('error.message')
                ?? $response->body();

            throw new RuntimeException('YCloud: '.$msg);
        }

        $data = $response->json() ?? [];

        return [
            'sid' => $data['id'] ?? $data['wamid'] ?? $data['messageId'] ?? null,
            'status' => $data['status'] ?? 'accepted',
        ];
    }

    private function assertReady(): void
    {
        if (! config('whatsapp.enabled')) {
            throw new RuntimeException('WhatsApp deshabilitado (WHATSAPP_ENABLED=false).');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('YCloud no configurado. Revisa YCLOUD_API_KEY y YCLOUD_FROM en .env');
        }
    }

    /** +5219991234567 */
    public function normalizeTo(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            $digits = '521'.$digits;
        } elseif (str_starts_with($digits, '52') && strlen($digits) === 12) {
            $digits = '521'.substr($digits, 2);
        }

        if (strlen($digits) < 11) {
            throw new RuntimeException("Teléfono inválido: {$phone}");
        }

        return '+'.$digits;
    }

    private function normalizeFrom(string $from): string
    {
        $from = trim($from);
        if (str_starts_with($from, 'whatsapp:')) {
            $from = substr($from, 9);
        }
        if (! str_starts_with($from, '+')) {
            $from = $this->normalizeTo($from);
        }

        return $from;
    }
}
