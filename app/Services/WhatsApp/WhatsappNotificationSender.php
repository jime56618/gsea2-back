<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappNotification;
use Carbon\Carbon;
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

    /**
     * Envía de inmediato lo pendiente de una póliza con programado_para <= hoy
     * (pago del día + vencimiento del día, etc.). Omite "alta" si ya se envió aparte.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendDueForPoliza(int $polizaId, bool $includeAlta = false): array
    {
        $today = Carbon::today(config('whatsapp.timezone'))->toDateString();

        $query = WhatsappNotification::query()
            ->with('cobranzaCuota')
            ->where('poliza_id', $polizaId)
            ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
            ->whereDate('programado_para', '<=', $today)
            ->orderBy('id');

        if (! $includeAlta) {
            $query->where('tipo', '!=', WhatsappNotification::TIPO_ALTA);
        }

        $sent = 0;
        $failed = 0;

        foreach ($query->get() as $notification) {
            if ($notification->cobranzaCuota && $notification->cobranzaCuota->estatus !== 'pendiente') {
                $notification->update(['estatus' => WhatsappNotification::ESTATUS_CANCELADO]);

                continue;
            }

            if ($this->send($notification)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
