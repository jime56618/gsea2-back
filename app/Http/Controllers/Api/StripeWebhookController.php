<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'message' => 'Webhook no configurado.',
            ], 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            return response()->json([
                'message' => 'Firma inválida.',
            ], 400);
        }

        $eventId = isset($event->id) ? (string) $event->id : null;
        $eventType = isset($event->type) ? (string) $event->type : '';
        $eventCreated = isset($event->created) ? (int) $event->created : null;
        $object = $event->data->object ?? null;

        if (! $object) {
            return response()->json(['received' => true]);
        }

        // Idempotencia rápida por ID de evento
        if ($eventId && $this->subscriptions->isStripeEventProcessed($eventId)) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        $stripeSecret = config('services.stripe.secret');
        $stripe = (is_string($stripeSecret) && str_starts_with($stripeSecret, 'sk_test_'))
            ? new StripeClient($stripeSecret)
            : null;

        try {
            switch ($eventType) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $this->subscriptions->applyPaidCheckoutSession(
                        session: $object,
                        eventId: $eventId,
                        eventCreated: $eventCreated,
                        stripe: $stripe
                    );
                    break;

                case 'invoice.paid':
                case 'invoice.payment_succeeded':
                    $this->subscriptions->applyPaidInvoice(
                        invoice: $object,
                        eventId: $eventId,
                        eventCreated: $eventCreated,
                        stripe: $stripe
                    );
                    break;

                case 'invoice.payment_failed':
                    $this->subscriptions->handleInvoicePaymentFailed(
                        invoice: $object,
                        eventId: $eventId,
                        eventCreated: $eventCreated
                    );
                    break;

                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->subscriptions->syncStripeSubscriptionEvent(
                        stripeSub: $object,
                        eventType: $eventType,
                        eventId: $eventId,
                        eventCreated: $eventCreated
                    );
                    break;

                default:
                    // Otros eventos de Stripe se acusan de recibo sin alterar base de datos.
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('Error procesando Stripe Webhook', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'Error al procesar el evento.',
            ], 500);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
