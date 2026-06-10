<?php

namespace App\Console\Commands;

use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;

/** Bloquea promotorías cuyo trial de 14 días ya venció (programar diario en scheduler). */
class ExpireWorkspaceTrialsCommand extends Command
{
    protected $signature = 'subscriptions:expire-trials';

    protected $description = 'Marca como locked los workspaces con trial vencido';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireEndedTrials();
        $this->info("Workspaces bloqueados: {$count}");

        return self::SUCCESS;
    }
}
