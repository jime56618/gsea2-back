<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Database\Seeder;

/** Promotorías existentes sin suscripción: trial desde ahora o activar según necesidad. */
class WorkspaceSubscriptionBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(SubscriptionService::class);

        Workspace::query()
            ->doesntHave('subscription')
            ->each(fn (Workspace $ws) => $service->startTrialForWorkspace($ws));
    }
}
