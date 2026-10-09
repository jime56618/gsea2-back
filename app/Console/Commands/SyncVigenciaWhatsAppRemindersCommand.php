<?php

namespace App\Console\Commands;

use App\Models\Poliza;
use App\Services\WhatsApp\VigenciaReminderPlanner;
use Illuminate\Console\Command;

class SyncVigenciaWhatsAppRemindersCommand extends Command
{
    protected $signature = 'whatsapp:sync-vigencia
                            {--poliza= : ID de una póliza (opcional)}
                            {--workspace= : Solo pólizas de este workspace}';

    protected $description = 'Programa (o regenera) avisos WhatsApp de vencimiento según fin_vigencia';

    public function handle(VigenciaReminderPlanner $planner): int
    {
        if (! config('whatsapp.enabled')) {
            $this->warn('WHATSAPP_ENABLED=false — no se programó nada.');

            return self::SUCCESS;
        }

        $query = Poliza::query()
            ->with(['contratante', 'aseguradora', 'workspace'])
            ->whereNotNull('fin_vigencia')
            ->whereDate('fin_vigencia', '>=', now()->toDateString());

        if ($this->option('poliza')) {
            $query->whereKey((int) $this->option('poliza'));
        }

        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $count = 0;
        $query->orderBy('id')->chunkById(50, function ($polizas) use ($planner, &$count) {
            foreach ($polizas as $poliza) {
                $planner->syncForPoliza($poliza);
                $count++;
            }
        });

        $this->info("Vigencia WhatsApp sincronizada para {$count} póliza(s).");

        return self::SUCCESS;
    }
}
