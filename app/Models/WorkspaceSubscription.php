<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de suscripción de una promotoría (trial 14d, bloqueo, pago).
 */
class WorkspaceSubscription extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_CANCELED = 'canceled';

    public const RENEWAL_AUTOMATIC = 'automatic';

    public const RENEWAL_MANUAL = 'manual';

    protected $fillable = [
        'workspace_id',
        'plan_id',
        'status',
        'renewal_mode',
        'trial_ends_at',
        'current_period_ends_at',
        'locked_at',
        'payment_failed_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'last_stripe_checkout_session_id',
        'last_stripe_invoice_id',
        'last_stripe_payment_intent_id',
        'stripe_latest_event_created_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'locked_at' => 'datetime',
            'payment_failed_at' => 'datetime',
            'stripe_latest_event_created_at' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        if (! $this->current_period_ends_at) {
            return null;
        }

        $graceDays = max(0, (int) config('workspace.payment_grace_days', 3));

        // Si hubo un fallo de cobro de renovación en una fecha posterior al vencimiento del ciclo,
        // el periodo de gracia de 3 días corre desde payment_failed_at o desde current_period_ends_at (el más reciente).
        $baseDate = $this->current_period_ends_at->copy();
        if ($this->payment_failed_at && $this->payment_failed_at->greaterThan($baseDate)) {
            $baseDate = $this->payment_failed_at->copy();
        }

        return $baseDate->addDays($graceDays);
    }

    public function isInGracePeriod(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();
        $graceEnds = $this->graceEndsAt();

        if ($this->payment_failed_at !== null) {
            return $graceEnds !== null && $graceEnds->greaterThan($now);
        }

        if (! $this->current_period_ends_at) {
            return false;
        }

        return $this->current_period_ends_at->isPast()
            && $graceEnds !== null
            && $graceEnds->greaterThan($now);
    }

    public function isAccessible(): bool
    {
        if ($this->status === self::STATUS_LOCKED) {
            return false;
        }

        if ($this->status === self::STATUS_ACTIVE) {
            if ($this->current_period_ends_at === null) {
                return true;
            }

            $graceEnds = $this->graceEndsAt();

            return $graceEnds ? $graceEnds->isFuture() : $this->current_period_ends_at->isFuture();
        }

        if ($this->status === self::STATUS_CANCELED) {
            // Si canceló la renovación automática pero su mes pagado sigue vigente, conserva acceso hasta fin de ciclo.
            return $this->current_period_ends_at !== null && $this->current_period_ends_at->isFuture();
        }

        if ($this->status === self::STATUS_TRIALING) {
            return $this->trial_ends_at === null || $this->trial_ends_at->isFuture();
        }

        return false;
    }
}
