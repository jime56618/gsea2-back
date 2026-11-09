<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_subscriptions', 'renewal_mode')) {
                $table->string('renewal_mode', 20)->nullable()->after('status');
            }

            if (! Schema::hasColumn('workspace_subscriptions', 'last_stripe_checkout_session_id')) {
                $table->string('last_stripe_checkout_session_id', 191)->nullable()->after('stripe_subscription_id');
            }

            if (! Schema::hasColumn('workspace_subscriptions', 'last_stripe_invoice_id')) {
                $table->string('last_stripe_invoice_id', 191)->nullable()->after('last_stripe_checkout_session_id');
            }

            if (! Schema::hasColumn('workspace_subscriptions', 'last_stripe_payment_intent_id')) {
                $table->string('last_stripe_payment_intent_id', 191)->nullable()->after('last_stripe_invoice_id');
            }

            if (! Schema::hasColumn('workspace_subscriptions', 'stripe_latest_event_created_at')) {
                $table->unsignedBigInteger('stripe_latest_event_created_at')->nullable()->after('last_stripe_payment_intent_id');
            }

            if (! Schema::hasColumn('workspace_subscriptions', 'payment_failed_at')) {
                $table->timestamp('payment_failed_at')->nullable()->after('locked_at');
            }
        });

        if (! Schema::hasTable('stripe_webhook_events')) {
            Schema::create('stripe_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('stripe_event_id', 191)->nullable()->unique();
                $table->string('event_type', 100);
                $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
                $table->string('stripe_checkout_session_id', 191)->nullable()->index();
                $table->string('stripe_invoice_id', 191)->nullable()->index();
                $table->string('stripe_payment_intent_id', 191)->nullable()->index();
                $table->string('stripe_subscription_id', 191)->nullable()->index();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');

        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $columns = [
                'renewal_mode',
                'last_stripe_checkout_session_id',
                'last_stripe_invoice_id',
                'last_stripe_payment_intent_id',
                'stripe_latest_event_created_at',
                'payment_failed_at',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('workspace_subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
