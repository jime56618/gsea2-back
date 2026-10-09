<?php

namespace App\Console\Commands;

use App\Models\Poliza;
use App\Models\WhatsappNotification;
use App\Services\WhatsApp\VigenciaReminderPlanner;
use App\Services\WhatsApp\WhatsappNotificationSender;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendVigenciaWhatsAppCommand extends Command
{
    protected $signature = 'whatsapp:send-vigencia
                            {poliza? : ID de la póliza}
                            {--hoy : Todas las pólizas con fin_vigencia = hoy}
                            {--force : Reprograma vigencia y fuerza programado_para=hoy}';

    protected $description = 'Envía (o fuerza) el WhatsApp de vencimiento de una póliza / las que vencen hoy';

    public function handle(VigenciaReminderPlanner $planner, WhatsappNotificationSender $sender): int
    {
        if (! config('whatsapp.enabled')) {
            $this->error('WHATSAPP_ENABLED=false');

            return self::FAILURE;
        }

        $ids = [];

        if ($this->option('hoy')) {
            $ids = Poliza::query()
                ->whereDate('fin_vigencia', Carbon::today(config('whatsapp.timezone'))->toDateString())
                ->pluck('id')
                ->all();
        } elseif ($this->argument('poliza')) {
            $ids = [(int) $this->argument('poliza')];
        } else {
            $this->error('Indica un ID: php artisan whatsapp:send-vigencia 18');
            $this->line('O todas las que vencen hoy: php artisan whatsapp:send-vigencia --hoy');

            return self::FAILURE;
        }

        if ($ids === []) {
            $this->warn('No hay pólizas que coincidan.');

            return self::SUCCESS;
        }

        $today = Carbon::today(config('whatsapp.timezone'))->toDateString();
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($ids as $polizaId) {
            $poliza = Poliza::with(['contratante', 'aseguradora', 'workspace', 'cobranzaCuotas'])->find($polizaId);
            if (! $poliza) {
                $this->warn("Póliza {$polizaId} no existe.");

                continue;
            }

            $planner->syncForPoliza($poliza);

            if ($this->option('force')) {
                WhatsappNotification::query()
                    ->where('poliza_id', $polizaId)
                    ->where('tipo', 'like', 'vigencia_%')
                    ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
                    ->update(['programado_para' => $today]);
            }

            // Solo vigencia (no cobranza) para este comando manual
            $pending = WhatsappNotification::query()
                ->where('poliza_id', $polizaId)
                ->where('estatus', WhatsappNotification::ESTATUS_PENDIENTE)
                ->where('tipo', 'like', 'vigencia_%')
                ->whereDate('programado_para', '<=', $today)
                ->orderBy('id')
                ->get();

            $sent = 0;
            $failed = 0;
            foreach ($pending as $n) {
                if ($sender->send($n)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            $totalSent += $sent;
            $totalFailed += $failed;
            $this->info("Póliza {$polizaId} ({$poliza->numero_poliza}): enviados={$sent}, fallidos={$failed}");
        }

        $this->info("Total: enviados={$totalSent}, fallidos={$totalFailed}");

        return self::SUCCESS;
    }
}
