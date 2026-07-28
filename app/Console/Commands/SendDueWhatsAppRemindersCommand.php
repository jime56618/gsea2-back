<?php

namespace App\Console\Commands;

use App\Models\WhatsappNotification;
use App\Services\WhatsApp\WhatsappNotificationSender;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDueWhatsAppRemindersCommand extends Command
{
    protected $signature = 'whatsapp:send-due-reminders
                            {--date= : Fecha YYYY-MM-DD (default: hoy)}';

    protected $description = 'Envía recordatorios WhatsApp programados para la fecha indicada';

    public function handle(WhatsappNotificationSender $sender): int
    {
        if (! config('whatsapp.enabled')) {
            $this->warn('WHATSAPP_ENABLED=false — no se envió nada.');

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::today(config('whatsapp.timezone'))->toDateString();

        $query = WhatsappNotification::query()
            ->with(['cobranzaCuota', 'poliza'])
            ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
            ->whereDate('programado_para', '<=', $date)
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Sin recordatorios pendientes para {$date}.");

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $query->chunkById(50, function ($notifications) use ($sender, &$sent, &$failed, &$skipped) {
            foreach ($notifications as $notification) {
                if ($notification->cobranzaCuota && $notification->cobranzaCuota->estatus !== 'pendiente') {
                    $notification->update(['estatus' => WhatsappNotification::ESTATUS_CANCELADO]);
                    $skipped++;

                    continue;
                }

                if ($sender->send($notification)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        });

        $this->info("Fecha {$date}: enviados={$sent}, fallidos={$failed}, omitidos={$skipped}, total={$total}");

        return self::SUCCESS;
    }
}
