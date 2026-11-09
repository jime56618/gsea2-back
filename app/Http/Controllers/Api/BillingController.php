<?php

namespace App\Http\Controllers\Api;

use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gestión de suscripción, catálogo de planes y cambio de plan.
 */
class BillingController extends BaseApiController
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private WorkspaceAuthorizationService $authorization,
    ) {}

    public function status(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->subscriptions->reconcileWithStripe($workspace);
        $workspace->load(['plan', 'subscription.plan']);

        $plans = Plan::query()
            ->where('is_public', true)
            ->orderBy('precio_mensual')
            ->get()
            ->map(fn (Plan $p) => $this->subscriptions->planToArray($p));

        return response()->json([
            'workspace_id' => $workspace->id,
            'subscription' => $this->subscriptions->toApiArray($workspace),
            'plans' => $plans,
            'stripe_configured' => !empty(config('services.stripe.secret')),
            'saved_payment_method' => null,
        ]);
    }

    public function plans(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);

        $plans = Plan::query()
            ->where('is_public', true)
            ->orderBy('precio_mensual')
            ->get()
            ->map(fn (Plan $p) => $this->subscriptions->planToArray($p));

        return response()->json([
            'plans' => $plans,
        ]);
    }

    public function previewChange(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $request->validate([
            'plan_id' => 'required|integer|exists:planes,id',
            'renewal_mode' => 'nullable|in:automatic,manual',
        ]);

        $renewalMode = $request->input('renewal_mode', 'automatic');
        $workspace->load(['plan', 'subscription.plan']);
        $currentPlan = $workspace->plan ?? $this->subscriptions->forWorkspace($workspace)?->plan;
        $targetPlan = Plan::findOrFail($request->input('plan_id'));

        $sub = $this->subscriptions->forWorkspace($workspace);
        $blockers = $this->subscriptions->validatePlanLimits($workspace, $targetPlan);

        $currentPrice = (float) ($currentPlan?->precio_mensual ?? 0);
        $targetPrice = (float) $targetPlan->precio_mensual;
        $isDowngrade = $currentPlan ? ($targetPrice < $currentPrice) : false;

        if (!empty($blockers)) {
            return response()->json([
                'can_proceed' => false,
                'blockers' => $blockers,
                'is_downgrade' => $isDowngrade,
                'current_plan' => $currentPlan ? $this->subscriptions->planToArray($currentPlan) : null,
                'target_plan' => $this->subscriptions->planToArray($targetPlan),
            ]);
        }

        // Cotización del periodo y fechas según el flujo de Stripe Checkout
        $now = Carbon::now();
        $periodEnds = $sub?->current_period_ends_at;
        $isSamePlanActive = $sub
            && in_array($sub->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
            && (int) $sub->plan_id === (int) $targetPlan->id
            && $periodEnds
            && $periodEnds->isFuture();

        $amountDueToday = $targetPrice;
        $nextBillingAmount = $targetPrice;
        $isDeferredAutomatic = false;

        if ($renewalMode === 'manual') {
            if ($isSamePlanActive) {
                $effectiveDate = 'Al finalizar periodo actual (' . $periodEnds->format('d/m/Y') . ')';
                $nextCycle = $periodEnds->copy()->addMonth();
                $nextBillingDate = $nextCycle->format('d/m/Y') . ' (Pago manual)';
                $contractPeriodEnd = $nextCycle->format('d/m/Y');
                $summaryNote = "Renovación manual por 1 mes adicional. Se sumará a tu vigencia actual sin generar cargos recurrentes automáticos.";
            } else {
                $effectiveDate = 'Inmediata (al confirmar pago)';
                $nextCycle = $now->copy()->addMonth();
                $nextBillingDate = $nextCycle->format('d/m/Y') . ' (Pago manual)';
                $contractPeriodEnd = $nextCycle->format('d/m/Y');
                $summaryNote = "Pago único por 1 mes de vigencia. No se realizarán cargos automáticos posteriores.";
            }
        } else {
            if ($isSamePlanActive) {
                $isDeferredAutomatic = true;
                $amountDueToday = 0.00;
                $effectiveDate = 'Inmediata (primer cobro el ' . $periodEnds->format('d/m/Y') . ')';
                $nextBillingDate = $periodEnds->format('d/m/Y');
                $contractPeriodEnd = $periodEnds->format('d/m/Y');
                $summaryNote = 'Ya cuentas con un periodo pagado vigente hasta el ' . $periodEnds->format('d/m/Y') . '. Hoy no se realizará ningún cargo ($0.00 a pagar hoy); únicamente autorizarás tu método de pago en Stripe para programar el primer cobro automático el ' . $periodEnds->format('d/m/Y') . '.';
            } else {
                $effectiveDate = 'Inmediata (al confirmar pago)';
                $nextCycle = $now->copy()->addMonth();
                $nextBillingDate = $nextCycle->format('d/m/Y');
                $contractPeriodEnd = $nextCycle->format('d/m/Y');
                $summaryNote = "Suscripción mensual con renovación automática mediante Stripe Checkout.";
            }
        }

        $tax = 0.00;
        $total = $amountDueToday + $tax;
        $stripeConfigured = !empty(config('services.stripe.secret'));

        return response()->json([
            'can_proceed' => true,
            'is_downgrade' => $isDowngrade,
            'current_plan' => $currentPlan ? $this->subscriptions->planToArray($currentPlan) : null,
            'target_plan' => $this->subscriptions->planToArray($targetPlan),
            'quote' => [
                'amount_due_today' => $amountDueToday,
                'tax' => $tax,
                'total' => $total,
                'currency' => 'MXN',
                'effective_date' => $effectiveDate,
                'next_billing_date' => $nextBillingDate,
                'next_billing_amount' => $nextBillingAmount,
                'contract_period_end' => $contractPeriodEnd,
                'summary_note' => $summaryNote,
                'is_deferred_automatic' => $isDeferredAutomatic,
            ],
            'payment_gateway' => [
                'is_configured' => $stripeConfigured,
                'provider' => 'Stripe',
                'saved_payment_method' => null,
            ],
        ]);
    }

    public function changePlan(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $request->validate([
            'plan_id' => 'required|integer|exists:planes,id',
        ]);

        $stripeConfigured = !empty(config('services.stripe.secret'));

        if (! $stripeConfigured) {
            return response()->json([
                'message' => 'La pasarela de pagos con tarjeta no está configurada en el servidor. No se realizó ningún cargo.',
                'code' => 'PAYMENT_GATEWAY_NOT_CONFIGURED',
                'decision_pending' => 'Se requiere configurar las credenciales de Stripe (STRIPE_KEY, STRIPE_SECRET) y el webhook de pagos en el entorno del servidor.',
            ], 422);
        }

        return response()->json([
            'message' => 'Flujo de pasarela de pagos preparado.',
        ]);
    }

    public function checkout(Request $request, Workspace $workspace): JsonResponse
    {
        // Verificar que el usuario pertenece al workspace.
        $this->ensureMemberOfWorkspace($request, $workspace);

        // Verificar permisos para administrar la facturación.
        $this->authorizeBilling($request, $workspace);

        // Validar los datos enviados desde React.
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:planes,id',
            'renewal_mode' => 'required|in:automatic,manual',
        ]);

        // Obtener el plan solicitado.
        $plan = Plan::query()
            ->where('id', $validated['plan_id'])
            ->where('is_public', true)
            ->firstOrFail();

        // Validar que el uso actual del workspace no supere los límites del plan solicitado.
        $blockers = $this->subscriptions->validatePlanLimits($workspace, $plan);
        if (! empty($blockers)) {
            return response()->json([
                'message' => $blockers[0],
                'blockers' => $blockers,
                'code' => 'PLAN_LIMITS_EXCEEDED',
            ], 422);
        }

        $automatic = $validated['renewal_mode'] === 'automatic';

        // Buscar el precio configurado en Stripe según la modalidad de renovación.
        $priceId = $automatic
            ? config('stripe.prices.' . $plan->slug)
            : config('stripe.manual_prices.' . $plan->slug);

        $stripeSecret = config('services.stripe.secret');

        if (empty($priceId) || empty($stripeSecret)) {
            return response()->json([
                'message' => 'Stripe no está configurado para este plan o modalidad.',
                'code' => 'STRIPE_NOT_CONFIGURED',
            ], 422);
        }

        // Por seguridad, permitir únicamente claves de prueba en esta fase.
        if (! str_starts_with($stripeSecret, 'sk_test_')) {
            return response()->json([
                'message' => 'Checkout está habilitado únicamente en Stripe sandbox.',
            ], 503);
        }

        $stripe = new \Stripe\StripeClient($stripeSecret);

        // Reconciliar estado local con Stripe antes de validar suscripciones activas.
        $subscription = $this->subscriptions->reconcileWithStripe($workspace, $stripe);

        if ($subscription?->stripe_subscription_id) {
            $isScheduledCancel = $subscription->status === WorkspaceSubscription::STATUS_CANCELED
                && $subscription->current_period_ends_at
                && $subscription->current_period_ends_at->isFuture();

            if ($isScheduledCancel) {
                if ($automatic && (int) $subscription->plan_id === (int) $plan->id) {
                    return response()->json([
                        'message' => 'Tu suscripción actual sigue dentro del periodo pagado con cancelación programada. Puedes reactivar la renovación automática desde Administrar suscripción sin realizar un nuevo cobro.',
                        'code' => 'REACTIVATE_EXISTING_SUBSCRIPTION',
                    ], 409);
                }

                if ((int) $subscription->plan_id !== (int) $plan->id) {
                    return response()->json([
                        'message' => 'Ya cuentas con un periodo pagado vigente en tu plan actual (' . ($subscription->plan?->nombre ?? 'actual') . ') hasta el ' . $subscription->current_period_ends_at->format('d/m/Y') . '. Para evitar cobros duplicados sin prorrateo, podrás cambiar de plan al finalizar tu ciclo actual.',
                        'code' => 'ACTIVE_PERIOD_PLAN_CHANGE_RESTRICTED',
                    ], 409);
                }
            } else {
                return response()->json([
                    'message' => 'Este workspace ya tiene una suscripción automática de Stripe activa. Su renovación o cambio de plan requiere cancelar o gestionar la suscripción actual.',
                    'code' => 'EXISTING_STRIPE_SUBSCRIPTION',
                ], 409);
            }
        }

        // Si el workspace tiene un plan activo pagado manualmente cuyo ciclo aún sigue vigente,
        // impedir un cambio de plan con cobro completo sin prorrateo para evitar cobros incorrectos.
        // Sí se permite renovar el mismo plan por 1 mes adicional o pasar el mismo plan a renovación automática.
        if (
            $subscription
            && in_array($subscription->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
            && $subscription->current_period_ends_at
            && $subscription->current_period_ends_at->isFuture()
            && (int) $subscription->plan_id !== (int) $plan->id
        ) {
            return response()->json([
                'message' => 'Ya cuentas con un periodo pagado vigente en tu plan actual (' . ($subscription->plan?->nombre ?? 'actual') . ') hasta el ' . $subscription->current_period_ends_at->format('d/m/Y') . '. Para evitar cobros duplicados sin prorrateo, podrás cambiar de plan al finalizar tu ciclo actual.',
                'code' => 'ACTIVE_PERIOD_PLAN_CHANGE_RESTRICTED',
            ], 409);
        }

        // Si ya existe un cliente en Stripe para este workspace, verificar que no tenga ya una suscripción activa o programada en Stripe.
        if (! empty($subscription?->stripe_customer_id)) {
            try {
                $existingSubs = $stripe->subscriptions->all([
                    'customer' => $subscription->stripe_customer_id,
                    'status' => 'all',
                    'limit' => 10,
                ]);

                foreach ($existingSubs->data as $remoteSub) {
                    $remoteStatus = (string) ($remoteSub->status ?? '');
                    $isDeferredTrial = $remoteStatus === 'trialing'
                        && (string) ($remoteSub->metadata->deferred_start ?? '') === 'true';

                    if ($remoteStatus !== 'active' && ! $isDeferredTrial) {
                        continue;
                    }

                    $remoteWorkspaceId = (int) ($remoteSub->metadata->workspace_id ?? 0);
                    if ($remoteWorkspaceId > 0 && $remoteWorkspaceId !== (int) $workspace->id) {
                        continue;
                    }

                    $remoteCancelAtPeriodEnd = (bool) ($remoteSub->cancel_at_period_end ?? false);
                    if ($remoteCancelAtPeriodEnd && ! $automatic && (int) ($subscription?->plan_id ?? 0) === (int) $plan->id) {
                        continue;
                    }

                    $this->subscriptions->syncStripeSubscriptionEvent($remoteSub, 'subscription.reconciled');

                    return response()->json([
                        'message' => $remoteCancelAtPeriodEnd
                            ? 'Tu suscripción actual sigue dentro del periodo pagado con cancelación programada. Puedes reactivar la renovación automática desde Administrar suscripción sin realizar un nuevo cobro.'
                            : 'Este workspace ya cuenta con una suscripción activa en Stripe.',
                        'code' => $remoteCancelAtPeriodEnd ? 'REACTIVATE_EXISTING_SUBSCRIPTION' : 'EXISTING_STRIPE_SUBSCRIPTION',
                    ], 409);
                }
            } catch (\Throwable) {
                // Continuar si el customer no existe en el entorno actual de sandbox
            }
        }

        // Si el usuario tiene el mismo plan pagado y vigente en modalidad manual, diferir el primer cobro automático
        // hasta el final del periodo actual ya pagado ($0.00 a pagar hoy).
        $isSamePlanManualActive = $automatic
            && $subscription
            && empty($subscription->stripe_subscription_id)
            && in_array($subscription->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
            && (int) $subscription->plan_id === (int) $plan->id
            && $subscription->current_period_ends_at
            && $subscription->current_period_ends_at->isFuture();

        if ($isSamePlanManualActive) {
            // Stripe Checkout exige al menos 48 horas futuras cuando se especifica subscription_data.trial_end.
            $minimumTrialEnd = Carbon::now()->addHours(48)->addMinutes(2);
            if ($subscription->current_period_ends_at->lessThan($minimumTrialEnd)) {
                return response()->json([
                    'message' => 'Tu periodo actual vence el ' . $subscription->current_period_ends_at->format('d/m/Y H:i') . ' (en menos de 48 horas). Stripe requiere al menos 48 horas de anticipación para programar un cobro diferido sin cargo inicial; podrás activar la renovación automática en cuanto concluya tu ciclo actual.',
                    'code' => 'DEFERRED_TRIAL_END_TOO_SOON',
                ], 422);
            }
        }

        $frontendUrl = rtrim(config('services.frontend.url'), '/');

        $metadata = [
            'workspace_id' => (string) $workspace->id,
            'plan_id' => (string) $plan->id,
            'plan_slug' => (string) $plan->slug,
            'renewal_mode' => $validated['renewal_mode'],
        ];

        if ($isSamePlanManualActive) {
            $metadata['deferred_start'] = 'true';
            $metadata['deferred_period_end'] = (string) $subscription->current_period_ends_at->getTimestamp();
        }

        $sessionData = [
            'mode' => $automatic ? 'subscription' : 'payment',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $frontendUrl . '/configuracion/facturacion?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl . '/configuracion/facturacion?checkout=cancel',
            'client_reference_id' => (string) $workspace->id,
            'metadata' => $metadata,
        ];

        if (! empty($subscription?->stripe_customer_id)) {
            $sessionData['customer'] = $subscription->stripe_customer_id;
        } elseif (! empty($request->user()?->email)) {
            $sessionData['customer_email'] = $request->user()->email;
        }

        if ($automatic) {
            $sessionData['subscription_data'] = [
                'metadata' => $metadata,
            ];
            if ($isSamePlanManualActive) {
                $sessionData['subscription_data']['trial_end'] = $subscription->current_period_ends_at->getTimestamp();
            }
        } else {
            $sessionData['payment_intent_data'] = [
                'metadata' => $metadata,
            ];
        }

        try {
            $session = $stripe->checkout->sessions->create($sessionData);

            return response()->json([
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::error('Stripe Checkout error', [
                'workspace_id' => $workspace->id,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible iniciar el pago con Stripe.',
            ], 502);
        }
    }

    /**
     * Verifica de forma segura en el backend el estado de una sesión de Stripe Checkout al regresar al frontend.
     * Solo activa la suscripción si Stripe confirma payment_status === 'paid' o una suscripción diferida autorizada válida.
     */
    public function verifyCheckoutSession(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $validated = $request->validate([
            'session_id' => 'required|string|max:191',
        ]);

        $stripeSecret = config('services.stripe.secret');

        if (empty($stripeSecret) || ! str_starts_with($stripeSecret, 'sk_test_')) {
            return response()->json([
                'message' => 'Stripe sandbox no está configurado.',
            ], 503);
        }

        try {
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $session = $stripe->checkout->sessions->retrieve($validated['session_id'], [
                'expand' => ['line_items'],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return response()->json([
                'message' => 'No fue posible verificar la sesión de pago con Stripe.',
            ], 422);
        }

        $sessionWorkspaceId = (int) ($session->metadata->workspace_id ?? $session->client_reference_id ?? 0);

        if ($sessionWorkspaceId !== (int) $workspace->id) {
            return response()->json([
                'message' => 'La sesión de pago no corresponde a esta promotoría.',
            ], 403);
        }

        $subscription = $this->subscriptions->forWorkspace($workspace);
        $sessionCustomerId = is_object($session->customer ?? null)
            ? (string) $session->customer->id
            : (! empty($session->customer) ? (string) $session->customer : null);

        if (
            ! empty($subscription?->stripe_customer_id)
            && ! empty($sessionCustomerId)
            && $subscription->stripe_customer_id !== $sessionCustomerId
        ) {
            return response()->json([
                'message' => 'El cliente de pago no coincide con el registrado para esta promotoría.',
            ], 403);
        }

        $priceId = ! empty($session->line_items->data[0]->price->id)
            ? (string) $session->line_items->data[0]->price->id
            : null;
        $planFromPrice = $this->subscriptions->resolvePlanByStripePriceId($priceId);
        $metadataPlanId = (int) ($session->metadata->plan_id ?? 0);

        if ($planFromPrice && $metadataPlanId > 0 && (int) $planFromPrice->id !== $metadataPlanId) {
            return response()->json([
                'message' => 'El plan pagado en Stripe no coincide con la referencia de la sesión.',
            ], 422);
        }

        $paymentStatus = (string) ($session->payment_status ?? '');
        $isPaid = $paymentStatus === 'paid';
        $isDeferredAuthorized = $paymentStatus === 'no_payment_required'
            && (string) ($session->status ?? '') === 'complete'
            && (string) ($session->mode ?? '') === 'subscription'
            && ! empty($session->subscription)
            && (string) ($session->metadata->deferred_start ?? '') === 'true'
            && $subscription
            && in_array($subscription->status, [WorkspaceSubscription::STATUS_ACTIVE, WorkspaceSubscription::STATUS_CANCELED], true)
            && $metadataPlanId > 0
            && (int) $subscription->plan_id === $metadataPlanId
            && $subscription->current_period_ends_at
            && $subscription->current_period_ends_at->isFuture();

        if (! $isPaid && ! $isDeferredAuthorized) {
            return response()->json([
                'verified' => false,
                'payment_status' => $session->payment_status ?? 'unpaid',
                'message' => 'El pago aún no ha sido confirmado por Stripe.',
                'subscription' => $this->subscriptions->toApiArray($workspace->fresh(['plan', 'subscription.plan'])),
            ], 202);
        }

        $applied = $this->subscriptions->applyPaidCheckoutSession(
            session: $session,
            eventId: null,
            eventCreated: isset($session->created) ? (int) $session->created : null,
            stripe: $stripe
        );

        if (! $applied) {
            return response()->json([
                'message' => 'No fue posible validar y aplicar la sesión de suscripción con Stripe.',
            ], 422);
        }

        $workspace->load(['plan', 'subscription.plan']);
        $subArray = $this->subscriptions->toApiArray($workspace);
        $periodEndStr = ! empty($subArray['current_period_ends_at'])
            ? Carbon::parse($subArray['current_period_ends_at'])->format('d/m/Y')
            : null;

        return response()->json([
            'verified' => true,
            'payment_status' => $paymentStatus,
            'message' => $isDeferredAuthorized
                ? ('Renovación automática autorizada correctamente ($0.00 cobrados hoy). Tu primer cobro automático se realizará el ' . ($periodEndStr ?? 'finalizar tu periodo actual') . '.')
                : 'Pago confirmado y suscripción actualizada correctamente.',
            'subscription' => $subArray,
        ]);
    }

    public function activate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        return response()->json([
            'message' => 'La activación directa está deshabilitada. Completa el pago mediante Stripe Checkout.',
            'code' => 'STRIPE_CHECKOUT_REQUIRED',
        ], 409);
    }

    /**
     * Programa la cancelación de la renovación automática al final del periodo pagado actual en Stripe.
     */
    public function cancelSubscription(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret) || ! str_starts_with((string) $stripeSecret, 'sk_test_')) {
            return response()->json([
                'message' => 'Stripe sandbox no está configurado.',
            ], 503);
        }

        try {
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $result = $this->subscriptions->scheduleCancelAtPeriodEnd(
                workspace: $workspace,
                stripe: $stripe,
                operationType: 'subscription.cancel_scheduled'
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::error('Stripe cancel subscription error', [
                'workspace_id' => $workspace->id,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible programar la cancelación con Stripe en este momento.',
            ], 502);
        }

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'No fue posible cancelar la renovación automática.',
                'code' => $result['code'] ?? 'CANCEL_FAILED',
            ], (int) ($result['status'] ?? 422));
        }

        $periodEndStr = ! empty($result['subscription']['current_period_ends_at'])
            ? Carbon::parse($result['subscription']['current_period_ends_at'])->format('d/m/Y')
            : 'el final de tu ciclo actual';

        return response()->json([
            'message' => "Renovación automática cancelada. Tu promotoría conservará acceso completo hasta el {$periodEndStr} y no se realizarán cobros automáticos posteriores.",
            'subscription' => $result['subscription'],
        ]);
    }

    /**
     * Cambia una suscripción activa con renovación automática a renovación manual al finalizar el periodo actual,
     * desactivando el cobro recurrente en Stripe sin generar un cobro adicional ni reembolso.
     */
    public function switchToManual(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret) || ! str_starts_with((string) $stripeSecret, 'sk_test_')) {
            return response()->json([
                'message' => 'Stripe sandbox no está configurado.',
            ], 503);
        }

        try {
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $result = $this->subscriptions->scheduleCancelAtPeriodEnd(
                workspace: $workspace,
                stripe: $stripe,
                operationType: 'subscription.switched_to_manual'
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::error('Stripe switch to manual error', [
                'workspace_id' => $workspace->id,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible actualizar la modalidad de renovación con Stripe en este momento.',
            ], 502);
        }

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'No fue posible cambiar a renovación manual.',
                'code' => $result['code'] ?? 'SWITCH_TO_MANUAL_FAILED',
            ], (int) ($result['status'] ?? 422));
        }

        $periodEndStr = ! empty($result['subscription']['current_period_ends_at'])
            ? Carbon::parse($result['subscription']['current_period_ends_at'])->format('d/m/Y')
            : 'el final de tu ciclo actual';

        return response()->json([
            'message' => "Modalidad actualizada a renovación manual. Conservarás tu acceso actual hasta el {$periodEndStr} sin cargos automáticos y podrás renovar manualmente cuando lo desees.",
            'subscription' => $result['subscription'],
        ]);
    }

    /**
     * Reactiva la renovación automática (cancel_at_period_end = false) cuando la suscripción sigue dentro
     * de su periodo pagado vigente en Stripe, sin crear otra suscripción ni cobrar nuevamente.
     */
    public function reactivateSubscription(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);
        $this->authorizeBilling($request, $workspace);

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret) || ! str_starts_with((string) $stripeSecret, 'sk_test_')) {
            return response()->json([
                'message' => 'Stripe sandbox no está configurado.',
            ], 503);
        }

        try {
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $result = $this->subscriptions->reactivateScheduledCancellation(
                workspace: $workspace,
                stripe: $stripe
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::error('Stripe reactivate subscription error', [
                'workspace_id' => $workspace->id,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'No fue posible reactivar la renovación automática con Stripe en este momento.',
            ], 502);
        }

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'No fue posible reactivar la renovación automática.',
                'code' => $result['code'] ?? 'REACTIVATE_FAILED',
            ], (int) ($result['status'] ?? 422));
        }

        return response()->json([
            'message' => 'Renovación automática reactivada exitosamente. Tu suscripción continuará renovándose al finalizar el ciclo actual sin cargos adicionales hoy.',
            'subscription' => $result['subscription'],
        ]);
    }

    private function authorizeBilling(Request $request, Workspace $workspace): void
    {
        $user = $request->user();
        $isOwner = (int) $workspace->owner_id === (int) $user->id;

        if (! $isOwner) {
            $this->authorization->authorize($user, $workspace->id, 'billing.manage');
        }
    }

    private function ensureMemberOfWorkspace(Request $request, Workspace $workspace): void
    {
        $belongs = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists();

        if (! $belongs) {
            abort(response()->json(['message' => 'No tienes acceso a esta promotoría.'], 403));
        }
    }

    
}