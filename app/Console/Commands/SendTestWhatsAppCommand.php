<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Console\Command;

class SendTestWhatsAppCommand extends Command
{
    protected $signature = 'whatsapp:send-test {telefono : 10 dígitos MX o E.164} {mensaje?}';

    protected $description = 'Prueba envío WhatsApp (YCloud o Twilio según WHATSAPP_PROVIDER)';

    public function handle(WhatsAppGateway $whatsapp): int
    {
        if (! config('whatsapp.enabled')) {
            $this->error('Activa WHATSAPP_ENABLED=true en .env');

            return self::FAILURE;
        }

        if (! $whatsapp->isConfigured()) {
            $this->error('Proveedor '.$whatsapp->provider().' no configurado. Revisa .env (YCLOUD_* o TWILIO_*)');

            return self::FAILURE;
        }

        $telefono = $this->argument('telefono');
        $mensaje = $this->argument('mensaje')
            ?? '¡Hola! Prueba G-SEA — recordatorio de cobranza por WhatsApp.';

        $this->info('Proveedor: '.$whatsapp->provider());

        try {
            $result = $whatsapp->sendTest($telefono, $mensaje);
            $this->info('Enviado. SID: '.($result['sid'] ?? '—').' · status: '.$result['status']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
