<?php

namespace App\Services\WhatsApp;

use App\Models\CobranzaCuota;
use App\Models\Poliza;
use App\Models\WhatsappNotification;

/**
 * Fachada: elige YCloud o Twilio según WHATSAPP_PROVIDER.
 */
class WhatsAppGateway
{
    public function __construct(
        private YCloudWhatsAppService $ycloud,
        private TwilioWhatsAppService $twilio,
    ) {}

    public function provider(): string
    {
        return strtolower((string) config('whatsapp.provider', 'ycloud'));
    }

    public function isConfigured(): bool
    {
        return $this->provider() === 'twilio'
            ? $this->twilio->isConfigured()
            : $this->ycloud->isConfigured();
    }

    /**
     * Envía según tipo de notificación: plantilla YCloud si está configurada, si no texto.
     *
     * @return array{sid: string|null, status: string}
     */
    public function sendNotification(WhatsappNotification $notification): array
    {
        if ($this->provider() === 'twilio') {
            return $this->twilio->sendText($notification->telefono, $notification->mensaje);
        }

        $template = $this->templateNameForTipo($notification->tipo);

        if ($template) {
            $params = $this->templateParams($notification);

            return $this->ycloud->sendTemplate(
                $notification->telefono,
                $template,
                $params
            );
        }

        // Sin plantilla configurada: intenta texto (puede fallar fuera de ventana 24h).
        return $this->ycloud->sendText($notification->telefono, $notification->mensaje);
    }

    /**
     * @return array{sid: string|null, status: string}
     */
    public function sendTest(string $to, string $body): array
    {
        if ($this->provider() === 'twilio') {
            return $this->twilio->sendText($to, $body);
        }

        $testTemplate = config('whatsapp.ycloud.templates.test');
        if (filled($testTemplate)) {
            return $this->ycloud->sendTemplate($to, $testTemplate, [$body]);
        }

        return $this->ycloud->sendText($to, $body);
    }

    private function templateNameForTipo(string $tipo): ?string
    {
        $map = config('whatsapp.ycloud.templates', []);

        $key = match ($tipo) {
            WhatsappNotification::TIPO_ALTA => 'alta',
            WhatsappNotification::TIPO_RECORDATORIO_7D => 'recordatorio',
            WhatsappNotification::TIPO_RECORDATORIO_3D => 'recordatorio',
            WhatsappNotification::TIPO_RECORDATORIO_1D => 'recordatorio',
            WhatsappNotification::TIPO_DIA_PAGO => 'dia_pago',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $name = $map[$key] ?? null;

        return filled($name) ? $name : null;
    }

    /** @return list<string> */
    private function templateParams(WhatsappNotification $notification): array
    {
        $notification->loadMissing(['poliza.contratante', 'poliza.workspace', 'cobranzaCuota']);

        $poliza = $notification->poliza;
        $cuota = $notification->cobranzaCuota;

        $nombre = 'cliente';
        if ($poliza?->contratante?->nombre) {
            $nombre = trim($poliza->contratante->nombre);
        }

        $empresa = $poliza?->workspace?->nombre ?? config('app.name', 'G-SEA');
        $numero = $poliza?->numero_poliza ?? '—';

        // Alta a veces no traía cuota ligada: usar primera cuota pendiente o monto_cuota de la póliza.
        if ($cuota === null && $poliza) {
            $cuota = $poliza->cobranzaCuotas()
                ->where('estatus', 'pendiente')
                ->orderBy('fecha_programada')
                ->orderBy('numero_cuota')
                ->first();
        }

        $fecha = $cuota?->fecha_programada
            ? $cuota->fecha_programada->format('d/m/Y')
            : '—';

        if ($cuota) {
            $monto = '$'.number_format((float) $cuota->monto, 2).' '.($poliza->moneda ?? 'MXN');
        } elseif ($poliza?->monto_cuota !== null) {
            $monto = '$'.number_format((float) $poliza->monto_cuota, 2).' '.($poliza->moneda ?? 'MXN');
        } elseif ($poliza?->prima_total !== null) {
            $monto = '$'.number_format((float) $poliza->prima_total, 2).' '.($poliza->moneda ?? 'MXN');
        } else {
            $monto = '—';
        }

        // Orden alineado a plantillas sugeridas en docs/WHATSAPP_YCLOUD_SETUP.md
        return match ($notification->tipo) {
            WhatsappNotification::TIPO_ALTA => [$nombre, $empresa, $numero, $fecha, $monto],
            default => [$nombre, $numero, $fecha, $monto, $empresa],
        };
    }
}
