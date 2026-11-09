<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Models\WorkspaceUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public function activatePaid(
        Workspace $workspace,
        ?Plan $plan = null,
        ?Carbon $periodEnds = null,
        string $renewalMode = WorkspaceSubscription::RENEWAL_MANUAL,
        array $stripeAttributes = []
    ): WorkspaceSubscription {
        $plan = $plan ?? $workspace->plan ?? Plan::query()->where('slug', 'pro')->first();

        $attributes = [
            'plan_id' => $plan?->id,
            'status' => WorkspaceSubscription::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'current_period_ends_at' => $periodEnds ?? Carbon::now()->addMonth(),
            'locked_at' => null,
        ];

        if ($this->hasExtendedColumns()) {
            $attributes['renewal_mode'] = $renewalMode;
            $attributes['payment_failed_at'] = null;
        }

        foreach ($stripeAttributes as $key => $value) {
            if (in_array($key, ['stripe_customer_id', 'stripe_subscription_id'], true) || $this->hasExtendedColumns()) {
                $attributes[$key] = $value;
            }
        }

        $subscription = WorkspaceSubscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            $attributes
        );

        if ($plan) {
            $workspace->update(['plan_id' => $plan->id]);
        }

        return $subscription->fresh(['plan']);
    }

    /**
     * Valida si el uso actual del workspace excede los límites del plan destino.
     *
     * @return list<string>
     */
    public function validatePlanLimits(Workspace $workspace, Plan $targetPlan): array
    {
        $usage = $this->usageForWorkspace($workspace, $targetPlan);
        $blockers = [];
        $targetLimits = $targetPlan->limits ?? [];

        if (isset($targetLimits['users']) && (int) $targetLimits['users'] > 0 && $usage['users'] > (int) $targetLimits['users']) {
            $exceed = $usage['users'] - (int) $targetLimits['users'];
            $blockers[] = "Tu workspace cuenta actualmente con {$usage['users']} usuarios y el plan {$targetPlan->nombre} permite un máximo de {$targetLimits['users']}. Debes desvincular al menos {$exceed} colaborador(es) antes de cambiarte a este plan.";
        }

        if (isset($targetLimits['polizas']) && (int) $targetLimits['polizas'] > 0 && $usage['polizas'] > (int) $targetLimits['polizas']) {
            $exceed = $usage['polizas'] - (int) $targetLimits['polizas'];
            $blockers[] = "Tu workspace cuenta con {$usage['polizas']} pólizas activas y el plan {$targetPlan->nombre} admite hasta {$targetLimits['polizas']}. No es posible reducir el plan sin ajustar el volumen de pólizas previamente.";
        }

        if (isset($targetLimits['agentes']) && (int) $targetLimits['agentes'] > 0 && $usage['agentes'] > (int) $targetLimits['agentes']) {
            $exceed = $usage['agentes'] - (int) $targetLimits['agentes'];
            $blockers[] = "Tu workspace tiene registrados {$usage['agentes']} agentes y el plan {$targetPlan->nombre} admite un máximo de {$targetLimits['agentes']}. Debes desactivar al menos {$exceed} agente(s) para continuar.";
        }

        return $blockers;
    }

    /**
     * Resuelve el Plan local a partir de un Stripe Price ID configurado en config/stripe.php.
     */
    public function resolvePlanByStripePriceId(?string $priceId): ?Plan
    {
        if (! $priceId) {
            return null;
        }

        $autoPrices = (array) config('stripe.prices', []);
        foreach ($autoPrices as $slug => $configuredPrice) {
            if ($configuredPrice && (string) $configuredPrice === (string) $priceId) {
                return Plan::query()->where('slug', $slug)->first();
            }
        }

        $manualPrices = (array) config('stripe.manual_prices', []);
        foreach ($manualPrices as $slug => $configuredPrice) {
            if ($configuredPrice && (string) $configuredPrice === (string) $priceId) {
                return Plan::query()->where('slug', $slug)->first();
            }
        }

        return null;
    }

    /**
     * Comprueba si un evento de Stripe ya fue procesado previamente.
     */
    public function isStripeEventProcessed(?string $eventId): bool
    {
        if (! $eventId || ! $this->hasWebhookEventsTable()) {
            return false;
        }

        return DB::table('stripe_webhook_events')
            ->where('stripe_event_id', $eventId)
            ->exists();
    }

    /**
     * Comprueba si una operación de pago (Checkout Session, Invoice o PaymentIntent) ya fue aplicada
     * para activar o extender una suscripción, evitando duplicar vigencias ante múltiples eventos legítimos.
     */
    public function isStripePaymentApplied(
        ?string $checkoutSessionId = null,
        ?string $invoiceId = null,
        ?string $paymentIntentId = null
    ): bool {
        $paymentEventTypes = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            ' checkout.verify',
            'checkout.verify',
            'invoice.paid',
            'invoice.payment_succeeded',
        ];

        if ($this->hasWebhookEventsTable()) {
            $query = DB::table('stripe_webhook_events')
                ->whereIn('event_type', $paymentEventTypes)
                ->where(function ($q) use ($checkoutSessionId, $invoiceId, $paymentIntentId) {
                    $hasCondition = false;
                    if ($checkoutSessionId) {
                        $q->orWhere('stripe_checkout_session_id', $checkoutSessionId);
                        $hasCondition = true;
                    }
                    if ($invoiceId) {
                        $q->orWhere('stripe_invoice_id', $invoiceId);
                        $hasCondition = true;
                    }
                    if ($paymentIntentId) {
                        $q->orWhere('stripe_payment_intent_id', $paymentIntentId);
                        $hasCondition = true;
                    }
                    if (! $hasCondition) {
                        $q->whereRaw('1 = 0');
                    }
                });

            if ($query->exists()) {
                return true;
            }
        }

        if ($this->hasExtendedColumns()) {
            $subQuery = WorkspaceSubscription::query()->where(function ($q) use ($checkoutSessionId, $invoiceId, $paymentIntentId) {
                $hasCondition = false;
                if ($checkoutSessionId) {
                    $q->orWhere('last_stripe_checkout_session_id', $checkoutSessionId);
                    $hasCondition = true;
                }
                if ($invoiceId) {
                    $q->orWhere('last_stripe_invoice_id', $invoiceId);
                    $hasCondition = true;
                }
                if ($paymentIntentId) {
                    $q->orWhere('last_stripe_payment_intent_id', $paymentIntentId);
                    $hasCondition = true;
                }
                if (! $hasCondition) {
                    $q->whereRaw('1 = 0');
                }
            });

            if ($subQuery->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra un evento u operación de Stripe en la bitácora de idempotencia.
     * Solo stripe_event_id tiene restricción UNIQUE, permitiendo registrar múltiples eventos legítimos
     * sobre una misma factura o sesión sin error de clave duplicada.
     */
    public function recordStripeOperation(
        string $eventType,
        ?int $workspaceId = null,
        ?string $eventId = null,
        ?string $checkoutSessionId = null,
        ?string $invoiceId = null,
        ?string $paymentIntentId = null,
        ?string $subscriptionId = null
    ): void {
        if (! $this->hasWebhookEventsTable()) {
            return;
        }

        if ($eventId && $this->isStripeEventProcessed($eventId)) {
            return;
        }

        DB::table('stripe_webhook_events')->insert([
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'workspace_id' => $workspaceId,
            'stripe_checkout_session_id' => $checkoutSessionId,
            'stripe_invoice_id' => $invoiceId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_subscription_id' => $subscriptionId,
            'processed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function hasExtendedColumns(): bool
    {
        try {
            return Schema::hasColumn('workspace_subscriptions', 'renewal_mode');
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasWebhookEventsTable(): bool
    {
        try {
            return Schema::hasTable('stripe_webhook_events');
        } catch (\Throwable) {
            return false;
        }
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
                'renewal_mode' => null,
                'has_stripe_subscription' => false,
                'cancel_at_period_end' => false,
                'can_cancel_automatic' => false,
                'can_switch_to_manual' => false,
                'can_reactivate_automatic' => false,
                'can_renew_manual' => false,
                'is_locked' => true,
                'is_accessible' => false,
                'in_grace_period' => false,
                'grace_ends_at' => null,
                'trial_ends_at' => null,
                'days_left' => 0,
                'plan' => $plan ? $this->planToArray($plan) : null,
                'usage' => $this->usageForWorkspace($workspace, $plan),
            ];
        }

        $daysLeft = 0;
        if ($sub->status === WorkspaceSubscription::STATUS_TRIALING && $sub->trial_ends_at) {
            $daysLeft = max(0, (int) now()->diffInDays($sub->trial_ends_at, false));
        } elseif (
            in_array($sub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
            && $sub->current_period_ends_at
        ) {
            $daysLeft = max(0, (int) now()->diffInDays($sub->current_period_ends_at, false));
        }

        $renewalMode = $sub->renewal_mode;
        if (! $renewalMode) {
            if (! empty($sub->stripe_subscription_id) && $sub->status === WorkspaceSubscription::STATUS_ACTIVE) {
                $renewalMode = WorkspaceSubscription::RENEWAL_AUTOMATIC;
            } elseif (in_array($sub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)) {
                $renewalMode = WorkspaceSubscription::RENEWAL_MANUAL;
            }
        }

        $hasStripeSubscription = ! empty($sub->stripe_subscription_id);
        $hasFuturePeriod = $sub->current_period_ends_at !== null && $sub->current_period_ends_at->isFuture();

        $cancelAtPeriodEnd = $sub->status === WorkspaceSubscription::STATUS_CANCELED
            && $hasStripeSubscription
            && $hasFuturePeriod;

        $canCancelAutomatic = $sub->status === WorkspaceSubscription::STATUS_ACTIVE
            && $renewalMode === WorkspaceSubscription::RENEWAL_AUTOMATIC
            && $hasStripeSubscription
            && ! $cancelAtPeriodEnd;

        $canSwitchToManual = $canCancelAutomatic;
        $canReactivateAutomatic = $cancelAtPeriodEnd;
        $canRenewManual = (
            ($sub->status === WorkspaceSubscription::STATUS_ACTIVE && $renewalMode === WorkspaceSubscription::RENEWAL_MANUAL && ! $hasStripeSubscription)
            || ($sub->status === WorkspaceSubscription::STATUS_CANCELED && $hasFuturePeriod)
        );

        return [
            'status' => $sub->status,
            'renewal_mode' => $renewalMode,
            'has_stripe_subscription' => $hasStripeSubscription,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'can_cancel_automatic' => $canCancelAutomatic,
            'can_switch_to_manual' => $canSwitchToManual,
            'can_reactivate_automatic' => $canReactivateAutomatic,
            'can_renew_manual' => $canRenewManual,
            'is_locked' => $sub->isLocked() || ! $sub->isAccessible(),
            'is_accessible' => $sub->isAccessible(),
            'in_grace_period' => $sub->isInGracePeriod(),
            'grace_ends_at' => $sub->graceEndsAt()?->toIso8601String(),
            'payment_failed_at' => $sub->payment_failed_at?->toIso8601String(),
            'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $sub->current_period_ends_at?->toIso8601String(),
            'locked_at' => $sub->locked_at?->toIso8601String(),
            'days_left' => $daysLeft,
            'plan' => $plan ? $this->planToArray($plan) : null,
            'usage' => $this->usageForWorkspace($workspace, $plan),
        ];
    }

    /** @return array<string, mixed> */
    public function planToArray(Plan $plan): array
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
    public function usageForWorkspace(Workspace $workspace, ?Plan $plan): array
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

    /** Marca como locked todas las suscripciones en trial vencido o periodo pagado + días de gracia vencidos. */
    public function expireEndedTrials(): int
    {
        $count = 0;

        WorkspaceSubscription::query()
            ->where('status', WorkspaceSubscription::STATUS_TRIALING)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->with('workspace')
            ->each(function (WorkspaceSubscription $sub) use (&$count) {
                if ($sub->workspace) {
                    $this->lockWorkspace($sub->workspace);
                    $count++;
                }
            });

        $graceDays = max(0, (int) config('workspace.payment_grace_days', 3));
        $cutoff = now()->subDays($graceDays);

        WorkspaceSubscription::query()
            ->whereIn('status', [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED])
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', $cutoff)
            ->with('workspace')
            ->each(function (WorkspaceSubscription $sub) use (&$count) {
                if ($sub->workspace && ! $sub->isAccessible()) {
                    $this->lockWorkspace($sub->workspace);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Procesa de manera transaccional e idempotente una sesión de Stripe Checkout pagada
     * o una autorización diferida de renovación automática verificada contra Stripe.
     * Devuelve true si se aplicó o ya estaba aplicada, false si aún no está pagada o no es válida.
     */
    public function applyPaidCheckoutSession(
        object $session,
        ?string $eventId = null,
        ?int $eventCreated = null,
        ?\Stripe\StripeClient $stripe = null
    ): bool {
        $sessionId = isset($session->id) ? (string) $session->id : null;
        if (! $sessionId) {
            return false;
        }

        $paymentStatus = (string) ($session->payment_status ?? '');
        $sessionStatus = (string) ($session->status ?? '');
        $workspaceId = (int) ($session->metadata->workspace_id ?? $session->client_reference_id ?? 0);
        $planId = (int) ($session->metadata->plan_id ?? 0);
        $mode = (string) ($session->mode ?? '');
        $renewalMode = (string) ($session->metadata->renewal_mode ?? ($mode === 'subscription' ? 'automatic' : 'manual'));
        $isDeferredStart = (string) ($session->metadata->deferred_start ?? '') === 'true';

        $isPaid = $paymentStatus === 'paid';
        $isDeferredSubscription = $paymentStatus === 'no_payment_required'
            && $sessionStatus === 'complete'
            && $mode === 'subscription'
            && $isDeferredStart;

        if (! $isPaid && ! $isDeferredSubscription) {
            return false;
        }

        if ($workspaceId <= 0) {
            return false;
        }

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return false;
        }

        $plan = $planId > 0 ? Plan::query()->where('id', $planId)->where('is_public', true)->first() : null;
        $stripeCustomerId = is_object($session->customer ?? null)
            ? (string) $session->customer->id
            : (! empty($session->customer) ? (string) $session->customer : null);
        $stripeSubscriptionId = is_object($session->subscription ?? null)
            ? (string) $session->subscription->id
            : (! empty($session->subscription) ? (string) $session->subscription : null);
        $stripePaymentIntentId = is_object($session->payment_intent ?? null)
            ? (string) $session->payment_intent->id
            : (! empty($session->payment_intent) ? (string) $session->payment_intent : null);
        $stripeInvoiceId = is_object($session->invoice ?? null)
            ? (string) $session->invoice->id
            : (! empty($session->invoice) ? (string) $session->invoice : null);

        $periodEnd = null;
        $remoteCancelAtPeriodEnd = false;
        $priceId = ! empty($session->line_items->data[0]->price->id)
            ? (string) $session->line_items->data[0]->price->id
            : null;

        if ($isDeferredSubscription) {
            if (! $stripeSubscriptionId) {
                return false;
            }
            if (! $stripe) {
                $secret = config('services.stripe.secret');
                if (! empty($secret) && str_starts_with((string) $secret, 'sk_test_')) {
                    $stripe = new \Stripe\StripeClient($secret);
                }
            }
            if (! $stripe) {
                return false;
            }
        }

        if ($mode === 'subscription' && $stripeSubscriptionId && $stripe) {
            try {
                $stripeSub = $stripe->subscriptions->retrieve($stripeSubscriptionId);
                $remoteSubStatus = (string) ($stripeSub->status ?? '');

                if ($isDeferredSubscription) {
                    $remoteSubWorkspaceId = (int) ($stripeSub->metadata->workspace_id ?? 0);
                    $remoteSubPlanId = (int) ($stripeSub->metadata->plan_id ?? 0);
                    $remoteDeferredFlag = (string) ($stripeSub->metadata->deferred_start ?? '') === 'true';

                    if (
                        ! in_array($remoteSubStatus, ['trialing', 'active'], true)
                        || $remoteSubWorkspaceId !== $workspaceId
                        || ($planId > 0 && $remoteSubPlanId !== $planId)
                        || ! $remoteDeferredFlag
                    ) {
                        return false;
                    }
                }

                if (! empty($stripeSub->current_period_end)) {
                    $periodEnd = Carbon::createFromTimestamp((int) $stripeSub->current_period_end);
                }
                $remoteCancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false);
                if (! $priceId && ! empty($stripeSub->items->data[0]->price->id)) {
                    $priceId = (string) $stripeSub->items->data[0]->price->id;
                }
                if (! $stripeInvoiceId && ! empty($stripeSub->latest_invoice)) {
                    $stripeInvoiceId = is_object($stripeSub->latest_invoice)
                        ? (string) $stripeSub->latest_invoice->id
                        : (string) $stripeSub->latest_invoice;
                }
            } catch (\Throwable) {
                if ($isDeferredSubscription) {
                    return false;
                }
                // Si falla la consulta complementaria en un pago normal, usamos +1 mes como respaldo seguro.
            }
        }

        if ($priceId) {
            $planFromPrice = $this->resolvePlanByStripePriceId($priceId);
            if ($planFromPrice) {
                if ($planId > 0 && (int) $planFromPrice->id !== $planId) {
                    return false;
                }
                $plan = $planFromPrice;
            }
        }

        return DB::transaction(function () use (
            $workspace,
            $plan,
            $sessionId,
            $eventId,
            $eventCreated,
            $renewalMode,
            $mode,
            $isDeferredSubscription,
            $stripeCustomerId,
            $stripeSubscriptionId,
            $stripePaymentIntentId,
            $stripeInvoiceId,
            $periodEnd,
            $remoteCancelAtPeriodEnd
        ) {
            // Bloquear fila de suscripción del workspace para concurrencia entre Webhook y Verify
            $existingSub = WorkspaceSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            // Verificar idempotencia de evento y de operación de pago dentro de la transacción
            if (
                ($eventId && $this->isStripeEventProcessed($eventId))
                || $this->isStripePaymentApplied($sessionId, $stripeInvoiceId, $stripePaymentIntentId)
            ) {
                if ($eventId && ! $this->isStripeEventProcessed($eventId)) {
                    $this->recordStripeOperation(
                        eventType: 'checkout.session.completed',
                        workspaceId: $workspace->id,
                        eventId: $eventId,
                        checkoutSessionId: $sessionId,
                        invoiceId: $stripeInvoiceId,
                        paymentIntentId: $stripePaymentIntentId,
                        subscriptionId: $stripeSubscriptionId
                    );
                }

                return true;
            }

            $targetPlan = $plan ?? $existingSub?->plan ?? $workspace->plan ?? Plan::query()->where('slug', 'pro')->first();

            if (! $targetPlan) {
                return false;
            }

            if ($isDeferredSubscription) {
                // Seguridad estricta: payment_status = no_payment_required solo es válido si el workspace
                // ya tiene un periodo pagado vigente del mismo plan y mismo cliente.
                $hasValidPaidPeriod = $existingSub
                    && in_array($existingSub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
                    && (int) $existingSub->plan_id === (int) $targetPlan->id
                    && $existingSub->current_period_ends_at
                    && $existingSub->current_period_ends_at->isFuture();

                if (! $hasValidPaidPeriod) {
                    return false;
                }

                if (
                    ! empty($existingSub->stripe_customer_id)
                    && ! empty($stripeCustomerId)
                    && $existingSub->stripe_customer_id !== $stripeCustomerId
                ) {
                    return false;
                }

                // Conservar exactamente la vigencia ya pagada sin agregar otro mes hoy ni reiniciar trial.
                $effectivePeriodEnd = $existingSub->current_period_ends_at->copy();
                $effectiveRenewalMode = WorkspaceSubscription::RENEWAL_AUTOMATIC;
            } elseif ($mode === 'subscription' || $renewalMode === WorkspaceSubscription::RENEWAL_AUTOMATIC) {
                $effectivePeriodEnd = $periodEnd ?? Carbon::now()->addMonth();
                $effectiveRenewalMode = WorkspaceSubscription::RENEWAL_AUTOMATIC;
            } else {
                // Pago manual de 1 mes:
                // Si es renovación manual del mismo plan y su periodo actual aún está en el futuro, extender +1 mes desde current_period_ends_at.
                // Si estaba en trial, vencido, bloqueado o cambia de plan, inicia 1 mes desde hoy.
                $isSamePlanActive = $existingSub
                    && in_array($existingSub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
                    && (int) $existingSub->plan_id === (int) $targetPlan->id
                    && $existingSub->current_period_ends_at
                    && $existingSub->current_period_ends_at->isFuture();

                $baseDate = $isSamePlanActive ? $existingSub->current_period_ends_at->copy() : Carbon::now();
                $effectivePeriodEnd = $baseDate->addMonth();
                $effectiveRenewalMode = WorkspaceSubscription::RENEWAL_MANUAL;
                $stripeSubscriptionId = null;
            }

            $stripeAttrs = [
                'stripe_customer_id' => $stripeCustomerId ?? $existingSub?->stripe_customer_id,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'last_stripe_checkout_session_id' => $sessionId,
            ];

            if ($stripeInvoiceId) {
                $stripeAttrs['last_stripe_invoice_id'] = $stripeInvoiceId;
            }

            if ($stripePaymentIntentId) {
                $stripeAttrs['last_stripe_payment_intent_id'] = $stripePaymentIntentId;
            }

            if ($eventCreated) {
                $currentLatest = (int) ($existingSub?->stripe_latest_event_created_at ?? 0);
                $stripeAttrs['stripe_latest_event_created_at'] = max($currentLatest, $eventCreated);
            }

            $preserveScheduledCancel = $stripeSubscriptionId
                && (
                    $remoteCancelAtPeriodEnd
                    || (
                        $existingSub
                        && $existingSub->stripe_subscription_id === $stripeSubscriptionId
                        && $existingSub->status === WorkspaceSubscription::STATUS_CANCELED
                        && $eventCreated
                        && $existingSub->stripe_latest_event_created_at
                        && $eventCreated < $existingSub->stripe_latest_event_created_at
                    )
                );

            $sub = $this->activatePaid(
                $workspace,
                $targetPlan,
                $effectivePeriodEnd,
                $preserveScheduledCancel ? WorkspaceSubscription::RENEWAL_MANUAL : $effectiveRenewalMode,
                $stripeAttrs
            );

            if ($preserveScheduledCancel) {
                $sub->update([
                    'status' => WorkspaceSubscription::STATUS_CANCELED,
                    'renewal_mode' => WorkspaceSubscription::RENEWAL_MANUAL,
                ]);
            }

            $this->recordStripeOperation(
                eventType: 'checkout.session.completed',
                workspaceId: $workspace->id,
                eventId: $eventId,
                checkoutSessionId: $sessionId,
                invoiceId: $stripeInvoiceId,
                paymentIntentId: $stripePaymentIntentId,
                subscriptionId: $stripeSubscriptionId
            );

            return true;
        });
    }

    /**
     * Procesa de manera transaccional e idempotente el pago de una factura de renovación (invoice.paid / invoice.payment_succeeded).
     */
    public function applyPaidInvoice(
        object $invoice,
        ?string $eventId = null,
        ?int $eventCreated = null,
        ?\Stripe\StripeClient $stripe = null
    ): bool {
        $invoiceId = isset($invoice->id) ? (string) $invoice->id : null;
        if (! $invoiceId) {
            return false;
        }

        if (($invoice->paid ?? false) !== true && ($invoice->status ?? '') !== 'paid') {
            return false;
        }

        $stripeSubscriptionId = is_object($invoice->subscription ?? null)
            ? (string) $invoice->subscription->id
            : (! empty($invoice->subscription) ? (string) $invoice->subscription : null);
        $stripeCustomerId = is_object($invoice->customer ?? null)
            ? (string) $invoice->customer->id
            : (! empty($invoice->customer) ? (string) $invoice->customer : null);
        $stripePaymentIntentId = is_object($invoice->payment_intent ?? null)
            ? (string) $invoice->payment_intent->id
            : (! empty($invoice->payment_intent) ? (string) $invoice->payment_intent : null);

        $workspaceId = (int) ($invoice->subscription_details->metadata->workspace_id ?? $invoice->metadata->workspace_id ?? 0);
        $planId = (int) ($invoice->subscription_details->metadata->plan_id ?? $invoice->metadata->plan_id ?? 0);
        $isZeroAmountInvoice = (int) ($invoice->amount_paid ?? 0) === 0 && (int) ($invoice->total ?? 0) === 0;

        $periodEnd = null;
        $priceId = null;
        $remoteCancelAtPeriodEnd = false;
        $remoteIsDeferredStart = (string) ($invoice->subscription_details->metadata->deferred_start ?? $invoice->metadata->deferred_start ?? '') === 'true';

        if (! empty($invoice->lines->data) && is_array($invoice->lines->data)) {
            foreach ($invoice->lines->data as $line) {
                if (! empty($line->period->end)) {
                    $lineEnd = Carbon::createFromTimestamp((int) $line->period->end);
                    if (! $periodEnd || $lineEnd->greaterThan($periodEnd)) {
                        $periodEnd = $lineEnd;
                    }
                }
                if (! $priceId && ! empty($line->price->id)) {
                    $priceId = (string) $line->price->id;
                }
            }
        }

        if ($stripeSubscriptionId && $stripe) {
            try {
                $stripeSub = $stripe->subscriptions->retrieve($stripeSubscriptionId);
                if (! $periodEnd && ! empty($stripeSub->current_period_end)) {
                    $periodEnd = Carbon::createFromTimestamp((int) $stripeSub->current_period_end);
                }
                $remoteCancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false);
                if ((string) ($stripeSub->metadata->deferred_start ?? '') === 'true') {
                    $remoteIsDeferredStart = true;
                }
                if ($workspaceId <= 0 && ! empty($stripeSub->metadata->workspace_id)) {
                    $workspaceId = (int) $stripeSub->metadata->workspace_id;
                }
                if ($planId <= 0 && ! empty($stripeSub->metadata->plan_id)) {
                    $planId = (int) $stripeSub->metadata->plan_id;
                }
                if (! $priceId && ! empty($stripeSub->items->data[0]->price->id)) {
                    $priceId = (string) $stripeSub->items->data[0]->price->id;
                }
            } catch (\Throwable) {
                // Continuar con búsqueda local por stripe_subscription_id / stripe_customer_id
            }
        }

        return DB::transaction(function () use (
            $invoiceId,
            $eventId,
            $eventCreated,
            $stripeSubscriptionId,
            $stripeCustomerId,
            $stripePaymentIntentId,
            $workspaceId,
            $planId,
            $priceId,
            $periodEnd,
            $remoteCancelAtPeriodEnd,
            $isZeroAmountInvoice,
            $remoteIsDeferredStart
        ) {
            if (
                ($eventId && $this->isStripeEventProcessed($eventId))
                || $this->isStripePaymentApplied(null, $invoiceId, $stripePaymentIntentId)
            ) {
                return true;
            }

            $existingSub = null;
            if ($stripeSubscriptionId) {
                $existingSub = WorkspaceSubscription::query()
                    ->where('stripe_subscription_id', $stripeSubscriptionId)
                    ->lockForUpdate()
                    ->first();
            }
            if (! $existingSub && $workspaceId > 0) {
                $existingSub = WorkspaceSubscription::query()
                    ->where('workspace_id', $workspaceId)
                    ->lockForUpdate()
                    ->first();
            }
            if (! $existingSub && $stripeCustomerId) {
                $existingSub = WorkspaceSubscription::query()
                    ->where('stripe_customer_id', $stripeCustomerId)
                    ->lockForUpdate()
                    ->first();
            }

            $workspace = $existingSub?->workspace ?? ($workspaceId > 0 ? Workspace::find($workspaceId) : null);
            if (! $workspace) {
                return false;
            }

            // Re-verificar idempotencia bajo el candado
            if (
                ($eventId && $this->isStripeEventProcessed($eventId))
                || $this->isStripePaymentApplied(null, $invoiceId, $stripePaymentIntentId)
            ) {
                if ($eventId && ! $this->isStripeEventProcessed($eventId)) {
                    $this->recordStripeOperation(
                        eventType: 'invoice.paid',
                        workspaceId: $workspace->id,
                        eventId: $eventId,
                        checkoutSessionId: null,
                        invoiceId: $invoiceId,
                        paymentIntentId: $stripePaymentIntentId,
                        subscriptionId: $stripeSubscriptionId
                    );
                }

                return true;
            }

            $plan = $this->resolvePlanByStripePriceId($priceId)
                ?? ($planId > 0 ? Plan::query()->where('id', $planId)->where('is_public', true)->first() : null)
                ?? $existingSub?->plan
                ?? $workspace->plan
                ?? Plan::query()->where('slug', 'pro')->first();

            // Protección estricta: una factura inicial de $0.00 (generada por trial_end en cambio de manual a automática)
            // nunca puede activar un workspace sin pago vigente previo ni sumar un mes adicional.
            if ($isZeroAmountInvoice) {
                $hasValidPaidPeriod = $existingSub
                    && $remoteIsDeferredStart
                    && in_array($existingSub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
                    && $existingSub->current_period_ends_at
                    && $existingSub->current_period_ends_at->isFuture()
                    && $plan
                    && (int) $existingSub->plan_id === (int) $plan->id;

                if (! $hasValidPaidPeriod) {
                    return false;
                }

                $effectivePeriodEnd = $existingSub->current_period_ends_at->copy();
            } else {
                $effectivePeriodEnd = $periodEnd ?? Carbon::now()->addMonth();
                if ($existingSub?->current_period_ends_at && $existingSub->current_period_ends_at->greaterThan($effectivePeriodEnd)) {
                    // Evitar retroceder la fecha de fin de periodo si llega un evento antiguo
                    $effectivePeriodEnd = $existingSub->current_period_ends_at;
                }
            }

            $stripeAttrs = [
                'stripe_customer_id' => $stripeCustomerId ?? $existingSub?->stripe_customer_id,
                'stripe_subscription_id' => $stripeSubscriptionId ?? $existingSub?->stripe_subscription_id,
                'last_stripe_invoice_id' => $invoiceId,
            ];

            if ($stripePaymentIntentId) {
                $stripeAttrs['last_stripe_payment_intent_id'] = $stripePaymentIntentId;
            }

            if ($eventCreated) {
                $currentLatest = (int) ($existingSub?->stripe_latest_event_created_at ?? 0);
                $stripeAttrs['stripe_latest_event_created_at'] = max($currentLatest, $eventCreated);
            }

            $preserveScheduledCancel = $stripeSubscriptionId
                && (
                    $remoteCancelAtPeriodEnd
                    || (
                        $existingSub
                        && $existingSub->stripe_subscription_id === $stripeSubscriptionId
                        && $existingSub->status === WorkspaceSubscription::STATUS_CANCELED
                        && $eventCreated
                        && $existingSub->stripe_latest_event_created_at
                        && $eventCreated < $existingSub->stripe_latest_event_created_at
                    )
                );

            $renewalMode = $preserveScheduledCancel
                ? WorkspaceSubscription::RENEWAL_MANUAL
                : (
                    $stripeSubscriptionId
                        ? WorkspaceSubscription::RENEWAL_AUTOMATIC
                        : ($existingSub?->renewal_mode ?? WorkspaceSubscription::RENEWAL_MANUAL)
                );

            $sub = $this->activatePaid(
                $workspace,
                $plan,
                $effectivePeriodEnd,
                $renewalMode,
                $stripeAttrs
            );

            if ($preserveScheduledCancel) {
                $sub->update([
                    'status' => WorkspaceSubscription::STATUS_CANCELED,
                    'renewal_mode' => WorkspaceSubscription::RENEWAL_MANUAL,
                ]);
            }

            $this->recordStripeOperation(
                eventType: 'invoice.paid',
                workspaceId: $workspace->id,
                eventId: $eventId,
                checkoutSessionId: null,
                invoiceId: $invoiceId,
                paymentIntentId: $stripePaymentIntentId,
                subscriptionId: $stripeSubscriptionId
            );

            return true;
        });
    }

    /**
     * Registra un fallo de pago de factura (invoice.payment_failed) respetando los 3 días de gracia.
     */
    public function handleInvoicePaymentFailed(
        object $invoice,
        ?string $eventId = null,
        ?int $eventCreated = null
    ): bool {
        $invoiceId = isset($invoice->id) ? (string) $invoice->id : null;
        $stripeSubscriptionId = is_object($invoice->subscription ?? null)
            ? (string) $invoice->subscription->id
            : (! empty($invoice->subscription) ? (string) $invoice->subscription : null);
        $stripeCustomerId = is_object($invoice->customer ?? null)
            ? (string) $invoice->customer->id
            : (! empty($invoice->customer) ? (string) $invoice->customer : null);
        $workspaceId = (int) ($invoice->subscription_details->metadata->workspace_id ?? $invoice->metadata->workspace_id ?? 0);

        return DB::transaction(function () use ($invoiceId, $eventId, $eventCreated, $stripeSubscriptionId, $stripeCustomerId, $workspaceId) {
            if ($eventId && $this->isStripeEventProcessed($eventId)) {
                return true;
            }

            $sub = null;
            if ($stripeSubscriptionId) {
                $sub = WorkspaceSubscription::query()->where('stripe_subscription_id', $stripeSubscriptionId)->lockForUpdate()->first();
            }
            if (! $sub && $workspaceId > 0) {
                $sub = WorkspaceSubscription::query()->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            }
            if (! $sub && $stripeCustomerId) {
                $sub = WorkspaceSubscription::query()->where('stripe_customer_id', $stripeCustomerId)->lockForUpdate()->first();
            }

            if (! $sub) {
                return false;
            }

            // Ignorar si es un evento retrasado anterior al último estado aplicado
            if ($eventCreated && $this->hasExtendedColumns() && $sub->stripe_latest_event_created_at && $eventCreated < $sub->stripe_latest_event_created_at) {
                return true;
            }

            $updates = [];
            if ($this->hasExtendedColumns()) {
                $updates['payment_failed_at'] = $sub->payment_failed_at ?? Carbon::now();
                if ($eventCreated) {
                    $updates['stripe_latest_event_created_at'] = max((int) $sub->stripe_latest_event_created_at, $eventCreated);
                }
            }

            if (! empty($updates)) {
                $sub->update($updates);
                $sub->refresh();
            }

            // Solo bloquear si ya expiró el periodo actual MÁS los 3 días de gracia
            if (! $sub->isAccessible()) {
                $sub->update([
                    'status' => WorkspaceSubscription::STATUS_LOCKED,
                    'locked_at' => $sub->locked_at ?? Carbon::now(),
                ]);
            }

            if ($eventId) {
                $this->recordStripeOperation(
                    eventType: 'invoice.payment_failed',
                    workspaceId: $sub->workspace_id,
                    eventId: $eventId,
                    checkoutSessionId: null,
                    invoiceId: $invoiceId,
                    paymentIntentId: null,
                    subscriptionId: $stripeSubscriptionId
                );
            }

            return true;
        });
    }

    /**
     * Sincroniza cambios o cancelación de una suscripción de Stripe (customer.subscription.updated / deleted),
     * protegiendo contra eventos fuera de orden y respetando periodos vigentes y días de gracia.
     */
    public function syncStripeSubscriptionEvent(
        object $stripeSub,
        string $eventType,
        ?string $eventId = null,
        ?int $eventCreated = null
    ): bool {
        $stripeSubscriptionId = isset($stripeSub->id) ? (string) $stripeSub->id : null;
        if (! $stripeSubscriptionId) {
            return false;
        }

        $workspaceId = (int) ($stripeSub->metadata->workspace_id ?? 0);
        $planId = (int) ($stripeSub->metadata->plan_id ?? 0);
        $priceId = ! empty($stripeSub->items->data[0]->price->id) ? (string) $stripeSub->items->data[0]->price->id : null;
        $stripeStatus = (string) ($stripeSub->status ?? '');
        $isDeferredStart = (string) ($stripeSub->metadata->deferred_start ?? '') === 'true';
        $cancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false);
        $periodEnd = ! empty($stripeSub->current_period_end)
            ? Carbon::createFromTimestamp((int) $stripeSub->current_period_end)
            : null;

        return DB::transaction(function () use (
            $stripeSubscriptionId,
            $workspaceId,
            $planId,
            $priceId,
            $stripeStatus,
            $isDeferredStart,
            $cancelAtPeriodEnd,
            $periodEnd,
            $eventType,
            $eventId,
            $eventCreated
        ) {
            if ($eventId && $this->isStripeEventProcessed($eventId)) {
                return true;
            }

            $sub = WorkspaceSubscription::query()
                ->where('stripe_subscription_id', $stripeSubscriptionId)
                ->lockForUpdate()
                ->first();

            if (! $sub && $workspaceId > 0) {
                $sub = WorkspaceSubscription::query()
                    ->where('workspace_id', $workspaceId)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $sub) {
                return false;
            }

            // Si el workspace ya tiene otra suscripción distinta o pasó a manual con fecha posterior, no sobrescribir con un evento viejo
            if ($sub->stripe_subscription_id && $sub->stripe_subscription_id !== $stripeSubscriptionId) {
                return true;
            }

            if ($eventCreated && $this->hasExtendedColumns() && $sub->stripe_latest_event_created_at && $eventCreated < $sub->stripe_latest_event_created_at) {
                return true;
            }

            $plan = $this->resolvePlanByStripePriceId($priceId)
                ?? ($planId > 0 ? Plan::query()->where('id', $planId)->where('is_public', true)->first() : null)
                ?? $sub->plan;

            $isValidDeferredTrial = $stripeStatus === 'trialing'
                && $isDeferredStart
                && in_array($sub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
                && $sub->current_period_ends_at
                && $sub->current_period_ends_at->isFuture()
                && $plan
                && (int) $sub->plan_id === (int) $plan->id;

            // Si llega un evento trialing de Stripe que NO corresponde a un cambio diferido válido sobre un periodo ya pagado,
            // no alterar el estado ni confundirlo con el trial gratuito de GSEA.
            if ($stripeStatus === 'trialing' && ! $isValidDeferredTrial) {
                return true;
            }

            $updates = [];
            if ($plan && ($stripeStatus === 'active' || $isValidDeferredTrial)) {
                $updates['plan_id'] = $plan->id;
            }

            if (
                ! $isValidDeferredTrial
                && $periodEnd
                && (! $sub->current_period_ends_at || $periodEnd->greaterThanOrEqualTo($sub->current_period_ends_at))
            ) {
                $updates['current_period_ends_at'] = $periodEnd;
            }

            if ($eventCreated && $this->hasExtendedColumns()) {
                $updates['stripe_latest_event_created_at'] = max((int) $sub->stripe_latest_event_created_at, $eventCreated);
            }

            if ($eventType === 'customer.subscription.deleted' || in_array($stripeStatus, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
                $updates['stripe_subscription_id'] = null;
                if ($this->hasExtendedColumns()) {
                    $updates['renewal_mode'] = WorkspaceSubscription::RENEWAL_MANUAL;
                }
                $effectiveEnd = $updates['current_period_ends_at'] ?? $sub->current_period_ends_at;

                // Si el usuario ya había renovado manualmente extendiendo su vigencia más allá del ciclo de la suscripción eliminada, conservar activo
                $hasExtendedManualPeriod = $sub->stripe_subscription_id === null
                    && $sub->status === WorkspaceSubscription::STATUS_ACTIVE
                    && $effectiveEnd
                    && $effectiveEnd->isFuture()
                    && (! $periodEnd || $effectiveEnd->greaterThan($periodEnd));

                $inGrace = $sub->payment_failed_at !== null && $sub->graceEndsAt()?->isFuture();

                if ($hasExtendedManualPeriod || $inGrace) {
                    $updates['status'] = WorkspaceSubscription::STATUS_ACTIVE;
                } elseif (
                    $stripeStatus === 'canceled'
                    && $sub->payment_failed_at === null
                    && $effectiveEnd
                    && $effectiveEnd->isFuture()
                ) {
                    // Cancelación voluntaria antes de finalizar el ciclo pagado: conserva acceso hasta current_period_ends_at
                    $updates['status'] = WorkspaceSubscription::STATUS_CANCELED;
                } else {
                    $updates['status'] = WorkspaceSubscription::STATUS_LOCKED;
                    $updates['locked_at'] = $sub->locked_at ?? Carbon::now();
                }
            } elseif ($stripeStatus === 'active' || $isValidDeferredTrial) {
                $updates['stripe_subscription_id'] = $stripeSubscriptionId;
                $updates['trial_ends_at'] = null;
                $updates['locked_at'] = null;

                if ($cancelAtPeriodEnd) {
                    // Cancelación programada al final del periodo: conserva acceso pero no renovará automáticamente
                    $updates['status'] = WorkspaceSubscription::STATUS_CANCELED;
                    if ($this->hasExtendedColumns()) {
                        $updates['renewal_mode'] = WorkspaceSubscription::RENEWAL_MANUAL;
                        $updates['payment_failed_at'] = null;
                    }
                } else {
                    $updates['status'] = WorkspaceSubscription::STATUS_ACTIVE;
                    if ($this->hasExtendedColumns()) {
                        $updates['renewal_mode'] = WorkspaceSubscription::RENEWAL_AUTOMATIC;
                        $updates['payment_failed_at'] = null;
                    }
                }

                if ($plan && $sub->workspace) {
                    $sub->workspace->update(['plan_id' => $plan->id]);
                }
            } elseif ($stripeStatus === 'past_due') {
                if ($this->hasExtendedColumns()) {
                    $updates['payment_failed_at'] = $sub->payment_failed_at ?? Carbon::now();
                }
            }

            if (! empty($updates)) {
                $sub->update($updates);
            }

            if ($eventId) {
                $this->recordStripeOperation(
                    eventType: $eventType,
                    workspaceId: $sub->workspace_id,
                    eventId: $eventId,
                    checkoutSessionId: null,
                    invoiceId: null,
                    paymentIntentId: null,
                    subscriptionId: $stripeSubscriptionId
                );
            }

            return true;
        });
    }

    /**
     * Reconcilia de forma segura el estado de MySQL con Stripe cuando existe un stripe_subscription_id registrado,
     * garantizando consistencia si algún webhook no llegó o si hubo una actualización previa en Stripe.
     */
    public function reconcileWithStripe(Workspace $workspace, ?\Stripe\StripeClient $stripe = null): ?WorkspaceSubscription
    {
        $sub = $this->forWorkspace($workspace);
        if (! $sub || empty($sub->stripe_subscription_id)) {
            return $sub;
        }

        if (! $stripe) {
            $secret = config('services.stripe.secret');
            if (empty($secret) || ! str_starts_with((string) $secret, 'sk_test_')) {
                return $sub;
            }
            $stripe = new \Stripe\StripeClient($secret);
        }

        try {
            $remoteSub = $stripe->subscriptions->retrieve($sub->stripe_subscription_id);

            $remoteWorkspaceId = (int) ($remoteSub->metadata->workspace_id ?? 0);
            if ($remoteWorkspaceId > 0 && $remoteWorkspaceId !== (int) $workspace->id) {
                return $sub;
            }

            $remoteCustomerId = is_object($remoteSub->customer ?? null)
                ? (string) $remoteSub->customer->id
                : (! empty($remoteSub->customer) ? (string) $remoteSub->customer : null);

            if (! empty($sub->stripe_customer_id) && ! empty($remoteCustomerId) && $sub->stripe_customer_id !== $remoteCustomerId) {
                return $sub;
            }

            $this->syncStripeSubscriptionEvent(
                stripeSub: $remoteSub,
                eventType: 'subscription.reconciled',
                eventId: null,
                eventCreated: null
            );

            return $this->forWorkspace($workspace);
        } catch (\Throwable) {
            return $sub;
        }
    }

    /**
     * Programa la cancelación de la renovación automática al final del periodo pagado (cancel_at_period_end = true)
     * o el cambio de renovación automática a manual sin reembolsos ni pérdida anticipada de acceso.
     *
     * @return array<string, mixed>
     */
    public function scheduleCancelAtPeriodEnd(
        Workspace $workspace,
        \Stripe\StripeClient $stripe,
        string $operationType = 'subscription.cancel_scheduled'
    ): array {
        $sub = $this->forWorkspace($workspace);

        if (! $sub || empty($sub->stripe_subscription_id)) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'NO_ACTIVE_STRIPE_SUBSCRIPTION',
                'message' => 'Esta promotoría no cuenta con una suscripción automática activa en Stripe para cancelar.',
            ];
        }

        $remoteSub = $stripe->subscriptions->retrieve($sub->stripe_subscription_id);

        $remoteWorkspaceId = (int) ($remoteSub->metadata->workspace_id ?? 0);
        if ($remoteWorkspaceId > 0 && $remoteWorkspaceId !== (int) $workspace->id) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'SUBSCRIPTION_WORKSPACE_MISMATCH',
                'message' => 'La suscripción en Stripe no corresponde a esta promotoría.',
            ];
        }

        $remoteCustomerId = is_object($remoteSub->customer ?? null)
            ? (string) $remoteSub->customer->id
            : (! empty($remoteSub->customer) ? (string) $remoteSub->customer : null);

        if (! empty($sub->stripe_customer_id) && ! empty($remoteCustomerId) && $sub->stripe_customer_id !== $remoteCustomerId) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'SUBSCRIPTION_CUSTOMER_MISMATCH',
                'message' => 'El cliente de la suscripción no coincide con el registrado para esta promotoría.',
            ];
        }

        $remoteStatus = (string) ($remoteSub->status ?? '');
        if (in_array($remoteStatus, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $this->syncStripeSubscriptionEvent($remoteSub, 'customer.subscription.deleted');

            return [
                'ok' => false,
                'status' => 409,
                'code' => 'SUBSCRIPTION_ALREADY_ENDED',
                'message' => 'La suscripción automática ya había finalizado en Stripe.',
            ];
        }

        if (! (bool) ($remoteSub->cancel_at_period_end ?? false)) {
            $remoteSub = $stripe->subscriptions->update($sub->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);
        }

        $periodEnd = ! empty($remoteSub->current_period_end)
            ? Carbon::createFromTimestamp((int) $remoteSub->current_period_end)
            : null;

        DB::transaction(function () use ($workspace, $remoteSub, $periodEnd, $operationType) {
            $lockedSub = WorkspaceSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSub) {
                return;
            }

            $effectiveEnd = $periodEnd;
            if ($lockedSub->current_period_ends_at && (! $effectiveEnd || $lockedSub->current_period_ends_at->greaterThan($effectiveEnd))) {
                $effectiveEnd = $lockedSub->current_period_ends_at;
            }

            $updates = [
                'status' => WorkspaceSubscription::STATUS_CANCELED,
                'stripe_subscription_id' => (string) $remoteSub->id,
                'current_period_ends_at' => $effectiveEnd,
                'locked_at' => null,
            ];

            if ($this->hasExtendedColumns()) {
                $updates['renewal_mode'] = WorkspaceSubscription::RENEWAL_MANUAL;
                $updates['stripe_latest_event_created_at'] = max(
                    (int) $lockedSub->stripe_latest_event_created_at,
                    time()
                );
            }

            $lockedSub->update($updates);

            $this->recordStripeOperation(
                eventType: $operationType,
                workspaceId: $workspace->id,
                eventId: null,
                checkoutSessionId: null,
                invoiceId: null,
                paymentIntentId: null,
                subscriptionId: (string) $remoteSub->id
            );
        });

        $workspace->load(['plan', 'subscription.plan']);

        return [
            'ok' => true,
            'subscription' => $this->toApiArray($workspace),
        ];
    }

    /**
     * Reactiva la renovación automática (cancel_at_period_end = false) cuando la suscripción de Stripe
     * sigue dentro de su periodo pagado vigente, sin crear otra suscripción ni generar un nuevo cobro.
     *
     * @return array<string, mixed>
     */
    public function reactivateScheduledCancellation(
        Workspace $workspace,
        \Stripe\StripeClient $stripe
    ): array {
        $sub = $this->forWorkspace($workspace);

        if (! $sub || empty($sub->stripe_subscription_id)) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'CHECKOUT_REQUIRED',
                'message' => 'El ciclo de la suscripción anterior ya concluyó. Para reactivar la renovación automática, contrata nuevamente tu plan mediante Stripe Checkout.',
            ];
        }

        $remoteSub = $stripe->subscriptions->retrieve($sub->stripe_subscription_id);

        $remoteWorkspaceId = (int) ($remoteSub->metadata->workspace_id ?? 0);
        if ($remoteWorkspaceId > 0 && $remoteWorkspaceId !== (int) $workspace->id) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'SUBSCRIPTION_WORKSPACE_MISMATCH',
                'message' => 'La suscripción en Stripe no corresponde a esta promotoría.',
            ];
        }

        $remoteCustomerId = is_object($remoteSub->customer ?? null)
            ? (string) $remoteSub->customer->id
            : (! empty($remoteSub->customer) ? (string) $remoteSub->customer : null);

        if (! empty($sub->stripe_customer_id) && ! empty($remoteCustomerId) && $sub->stripe_customer_id !== $remoteCustomerId) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'SUBSCRIPTION_CUSTOMER_MISMATCH',
                'message' => 'El cliente de la suscripción no coincide con el registrado para esta promotoría.',
            ];
        }

        $remoteStatus = (string) ($remoteSub->status ?? '');
        $isRemoteDeferredTrial = $remoteStatus === 'trialing'
            && (string) ($remoteSub->metadata->deferred_start ?? '') === 'true';
        $periodEnd = ! empty($remoteSub->current_period_end)
            ? Carbon::createFromTimestamp((int) $remoteSub->current_period_end)
            : null;

        if ((! in_array($remoteStatus, ['active'], true) && ! $isRemoteDeferredTrial) || ($periodEnd && $periodEnd->isPast())) {
            $this->syncStripeSubscriptionEvent($remoteSub, 'customer.subscription.deleted');

            return [
                'ok' => false,
                'status' => 409,
                'code' => 'CHECKOUT_REQUIRED',
                'message' => 'El periodo pagado de la suscripción ya finalizó en Stripe. Para reactivar tu plan, realiza una nueva contratación mediante Stripe Checkout.',
            ];
        }

        if ((bool) ($remoteSub->cancel_at_period_end ?? false)) {
            $remoteSub = $stripe->subscriptions->update($sub->stripe_subscription_id, [
                'cancel_at_period_end' => false,
            ]);
            if (! empty($remoteSub->current_period_end)) {
                $periodEnd = Carbon::createFromTimestamp((int) $remoteSub->current_period_end);
            }
        }

        DB::transaction(function () use ($workspace, $remoteSub, $periodEnd) {
            $lockedSub = WorkspaceSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSub) {
                return;
            }

            $effectiveEnd = $periodEnd;
            if ($lockedSub->current_period_ends_at && (! $effectiveEnd || $lockedSub->current_period_ends_at->greaterThan($effectiveEnd))) {
                $effectiveEnd = $lockedSub->current_period_ends_at;
            }

            $updates = [
                'status' => WorkspaceSubscription::STATUS_ACTIVE,
                'stripe_subscription_id' => (string) $remoteSub->id,
                'current_period_ends_at' => $effectiveEnd,
                'locked_at' => null,
            ];

            if ($this->hasExtendedColumns()) {
                $updates['renewal_mode'] = WorkspaceSubscription::RENEWAL_AUTOMATIC;
                $updates['payment_failed_at'] = null;
                $updates['stripe_latest_event_created_at'] = max(
                    (int) $lockedSub->stripe_latest_event_created_at,
                    time()
                );
            }

            $lockedSub->update($updates);

            $this->recordStripeOperation(
                eventType: 'subscription.reactivated',
                workspaceId: $workspace->id,
                eventId: null,
                checkoutSessionId: null,
                invoiceId: null,
                paymentIntentId: null,
                subscriptionId: (string) $remoteSub->id
            );
        });

        $workspace->load(['plan', 'subscription.plan']);

        return [
            'ok' => true,
            'subscription' => $this->toApiArray($workspace),
        ];
    }
}
