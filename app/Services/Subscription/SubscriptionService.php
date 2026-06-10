<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Models\WorkspaceUser;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida de suscripción por promotoría (workspace).
 */
class SubscriptionService
{
    public function startTrialForWorkspace(Workspace $workspace, ?Plan $plan = null): WorkspaceSubscription
    {
        $plan = $plan ?? Plan::query()->where('slug', 'pro')->first();
        $trialDays = (int) config('workspace.trial_days', 14);

        if ($plan?->trial_days) {
            $trialDays = (int) $plan->trial_days;
        }

        $subscription = WorkspaceSubscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'plan_id' => $plan?->id,
                'status' => WorkspaceSubscription::STATUS_TRIALING,
                'trial_ends_at' => Carbon::now()->addDays($trialDays),
                'locked_at' => null,
            ]
        );

        if ($plan && empty($workspace->plan_id)) {
            $workspace->update(['plan_id' => $plan->id]);
        }

        return $subscription->fresh(['plan']);
    }

    public function activatePaid(Workspace $workspace, ?Plan $plan = null, ?Carbon $periodEnds = null): WorkspaceSubscription
    {
        $plan = $plan ?? $workspace->plan ?? Plan::query()->where('slug', 'pro')->first();

        $subscription = WorkspaceSubscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'plan_id' => $plan?->id,
                'status' => WorkspaceSubscription::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'current_period_ends_at' => $periodEnds ?? Carbon::now()->addMonth(),
                'locked_at' => null,
            ]
        );

        if ($plan) {
            $workspace->update(['plan_id' => $plan->id]);
        }

        return $subscription->fresh(['plan']);
    }

    public function lockWorkspace(Workspace $workspace): WorkspaceSubscription
    {
        $subscription = WorkspaceSubscription::firstOrCreate(
            ['workspace_id' => $workspace->id],
            ['status' => WorkspaceSubscription::STATUS_LOCKED]
        );

        $subscription->update([
            'status' => WorkspaceSubscription::STATUS_LOCKED,
            'locked_at' => Carbon::now(),
        ]);

        return $subscription->fresh(['plan']);
    }

    public function forWorkspace(Workspace $workspace): ?WorkspaceSubscription
    {
        return $workspace->subscription()->with('plan')->first();
    }

    public function isAccessible(Workspace $workspace): bool
    {
        $sub = $this->forWorkspace($workspace);

        if (! $sub) {
            return false;
        }

        return $sub->isAccessible();
    }

    /** @return array<string, mixed> */
    public function toApiArray(Workspace $workspace): array
    {
        $sub = $this->forWorkspace($workspace);
        $plan = $sub?->plan ?? $workspace->plan;

        if (! $sub) {
            return [
                'status' => 'none',
                'is_locked' => true,
                'is_accessible' => false,
                'trial_ends_at' => null,
                'days_left' => 0,
                'plan' => $plan ? $this->planToArray($plan) : null,
                'usage' => $this->usageForWorkspace($workspace, $plan),
            ];
        }

        $daysLeft = 0;
        if ($sub->status === WorkspaceSubscription::STATUS_TRIALING && $sub->trial_ends_at) {
            $daysLeft = max(0, (int) now()->diffInDays($sub->trial_ends_at, false));
        }

        return [
            'status' => $sub->status,
            'is_locked' => $sub->isLocked() || ! $sub->isAccessible(),
            'is_accessible' => $sub->isAccessible(),
            'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $sub->current_period_ends_at?->toIso8601String(),
            'locked_at' => $sub->locked_at?->toIso8601String(),
            'days_left' => $daysLeft,
            'plan' => $plan ? $this->planToArray($plan) : null,
            'usage' => $this->usageForWorkspace($workspace, $plan),
        ];
    }

    /** @return array<string, mixed> */
    private function planToArray(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'nombre' => $plan->nombre,
            'precio_mensual' => $plan->precio_mensual,
            'limits' => $plan->limits ?? [],
            'features' => $plan->features ?? [],
        ];
    }

    /** @return array<string, int> */
    private function usageForWorkspace(Workspace $workspace, ?Plan $plan): array
    {
        $users = WorkspaceUser::query()->where('workspace_id', $workspace->id)->count();
        $polizas = $workspace->polizas()->count();
        $agentes = $workspace->agentes()->count();

        return [
            'users' => $users,
            'polizas' => $polizas,
            'agentes' => $agentes,
        ];
    }

    public function canAddUser(Workspace $workspace): bool
    {
        $plan = $workspace->plan;
        $limit = (int) ($plan?->limits['users'] ?? 0);

        if ($limit <= 0) {
            return true;
        }

        $count = WorkspaceUser::query()->where('workspace_id', $workspace->id)->count();

        return $count < $limit;
    }

    /** Marca como locked todas las suscripciones en trial vencido. */
    public function expireEndedTrials(): int
    {
        $count = 0;

        WorkspaceSubscription::query()
            ->where('status', WorkspaceSubscription::STATUS_TRIALING)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->with('workspace')
            ->each(function (WorkspaceSubscription $sub) use (&$count) {
                $this->lockWorkspace($sub->workspace);
                $count++;
            });

        return $count;
    }
}
