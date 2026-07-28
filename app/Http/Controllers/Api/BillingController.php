<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use App\Services\Authorization\WorkspaceAuthorizationService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de suscripción y activación manual (stub hasta integrar Stripe).
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
        $workspace->load(['plan', 'subscription.plan']);

        return response()->json([
            'workspace_id' => $workspace->id,
            'subscription' => $this->subscriptions->toApiArray($workspace),
        ]);
    }

    /**
     * Simula activación tras pago. En producción lo llamará el webhook de Stripe.
     * Owner del workspace o quien tenga billing.manage puede activar.
     */
    public function activate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMemberOfWorkspace($request, $workspace);

        $user = $request->user();
        $isOwner = (int) $workspace->owner_id === (int) $user->id;

        if (! $isOwner) {
            $this->authorization->authorize($user, $workspace->id, 'billing.manage');
        }

        $subscription = $this->subscriptions->activatePaid($workspace);

        return response()->json([
            'message' => 'Suscripción activada correctamente.',
            'subscription' => $this->subscriptions->toApiArray($workspace->fresh(['plan', 'subscription.plan'])),
            'workspace_subscription' => $subscription,
        ]);
    }

    private function ensureMemberOfWorkspace(Request $request, Workspace $workspace): void
    {
        $belongs = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists();

        if (! $belongs) {
            abort(response()->json(['message' => 'No tienes acceso a esta promotoría.'], 403));
        }
    }
}
