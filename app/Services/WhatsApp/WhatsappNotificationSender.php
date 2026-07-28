<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappNotification;
use Illuminate\Support\Facades\Log;

class WhatsappNotificationSender
{
    public function __construct(private WhatsAppGateway $whatsapp) {}

    public function send(WhatsappNotification $notification): bool
    {
        if ($notification->estatus !== WhatsappNotification::ESTATUS_PENDIENTE) {
            return false;
        }

        if (! $this->whatsapp->isConfigured()) {
            $provider = $this->whatsapp->provider();
            $notification->update([
                'estatus' => WhatsappNotification::ESTATUS_FALLIDO,
                'error_detalle' => "WhatsApp ({$provider}) no configurado en .env",
            ]);

            return false;
        }

        try {
            $result = $this->whatsapp->sendNotification($notification);

            $notification->update([
                'estatus' => WhatsappNotification::ESTATUS_ENVIADO,
                'twilio_sid' => $result['sid'],
                'enviado_en' => now(),
                'error_detalle' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp send failed', [
                'notification_id' => $notification->id,
                'provider' => $this->whatsapp->provider(),
                'error' => $e->getMessage(),
            ]);

            $notification->update([
                'estatus' => WhatsappNotification::ESTATUS_FALLIDO,
                'error_detalle' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
