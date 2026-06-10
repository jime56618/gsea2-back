<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Support\Str;

/**
 * Crea promotoría + membresía admin + trial 14 días (por workspace).
 */
class RegisterWorkspaceForOwner
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function execute(User $user, string $workspaceName): Workspace
    {
        $plan = \App\Models\Plan::query()->where('slug', 'pro')->first();

        $workspace = Workspace::create([
            'nombre' => $workspaceName,
            'slug' => Str::slug($workspaceName).'-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'plan_id' => $plan?->id,
        ]);

        $adminRole = Role::query()
            ->whereNull('workspace_id')
            ->where('slug', 'admin')
            ->first();

        if (! $adminRole) {
            $adminRole = Role::firstOrCreate(
                ['workspace_id' => null, 'slug' => 'admin'],
                ['nombre' => 'Administrador', 'is_system' => true]
            );
        }

        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
        ]);

        $user->current_workspace_id = $workspace->id;
        $user->save();

        $this->subscriptions->startTrialForWorkspace($workspace, $plan);

        return $workspace;
    }
}
