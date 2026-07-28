<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;

class ActivateWorkspaceBillingCommand extends Command
{
    protected $signature = 'billing:activate
                            {workspace? : ID del workspace (si omites, activa todos los locked/trialing vencidos)}
                            {--all : Activar todos los workspaces}';

    protected $description = 'Activa la suscripción de un workspace (desbloquea trial vencido / locked)';

    public function handle(SubscriptionService $subscriptions): int
    {
        if ($this->option('all') || ! $this->argument('workspace')) {
            $workspaces = Workspace::query()
                ->whereHas('subscription', function ($q) {
                    $q->whereIn('status', ['locked', 'trialing', 'canceled']);
                })
                ->get();

            if ($workspaces->isEmpty()) {
                $this->info('No hay workspaces bloqueados o en trial.');

                return self::SUCCESS;
            }

            foreach ($workspaces as $ws) {
                $subscriptions->activatePaid($ws);
                $this->info("Activado: #{$ws->id} {$ws->nombre}");
            }

            return self::SUCCESS;
        }

        $ws = Workspace::find($this->argument('workspace'));
        if (! $ws) {
            $this->error('Workspace no encontrado.');

            return self::FAILURE;
        }

        $subscriptions->activatePaid($ws);
        $this->info("Suscripción activa: #{$ws->id} {$ws->nombre}");

        return self::SUCCESS;
    }
}
