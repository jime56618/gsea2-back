<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TwilioWhatsAppService
{
    public function isConfigured(): bool
    {
        $sid = config('whatsapp.twilio.account_sid');
        $token = config('whatsapp.twilio.auth_token');
        $from = config('whatsapp.twilio.whatsapp_from');

        return filled($sid) && filled($token) && filled($from);
    }

    /**
     * @return array{sid: string|null, status: string}
     */
    public function sendText(string $toE164, string $body): array
    {
        if (! config('whatsapp.enabled')) {
            throw new RuntimeException('WhatsApp deshabilitado (WHATSAPP_ENABLED=false).');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Twilio no configurado. Revisa TWILIO_* en .env');
        }

        $sid = config('whatsapp.twilio.account_sid');
        $token = config('whatsapp.twilio.auth_token');
        $from = config('whatsapp.twilio.whatsapp_from');

        $to = $this->toWhatsAppAddress($toE164);

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $to,
                'Body' => $body,
            ]);

        if (! $response->successful()) {
            Log::warning('Twilio WhatsApp error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'to' => $to,
            ]);

            throw new RuntimeException(
                'Twilio: '.($response->json('message') ?? $response->body())
            );
        }

        $data = $response->json();

        return [
            'sid' => $data['sid'] ?? null,
            'status' => $data['status'] ?? 'queued',
        ];
    }

    /** Normaliza teléfono MX a whatsapp:+521XXXXXXXXXX */
    public function toWhatsAppAddress(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            $digits = '521'.$digits;
        } elseif (str_starts_with($digits, '52') && strlen($digits) === 12) {
            $digits = '521'.substr($digits, 2);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 11) {
            // US sandbox testers
        } elseif (strlen($digits) < 10) {
            throw new RuntimeException("Teléfono inválido: {$phone}");
        }

        return 'whatsapp:+'.$digits;
    }
}
