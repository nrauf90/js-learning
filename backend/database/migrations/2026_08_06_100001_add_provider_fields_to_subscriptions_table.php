<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Which provider owns this subscription. Rows created before the
            // Paddle switch stay 'manual' — they were driven by local wallet
            // payments and have no external counterpart to sync against.
            $table->string('provider', 32)->default('manual')->after('user_id');

            // Paddle subscription id (sub_…). The correlation key for every
            // subscription.* webhook; unique so replayed events upsert rather
            // than duplicate.
            $table->string('external_id', 64)->nullable()->unique()->after('provider');
            $table->string('external_price_id', 64)->nullable()->after('external_id');

            // Provider-driven lifecycle. `status` was already a free-form
            // string; these are the values we now actually write:
            // active | trialing | past_due | paused | canceled | expired.
            $table->timestamp('renews_at')->nullable()->after('ends_at');
            $table->timestamp('trial_ends_at')->nullable()->after('renews_at');
            $table->timestamp('canceled_at')->nullable()->after('trial_ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('canceled_at');
            $table->timestamp('paused_at')->nullable()->after('cancel_at_period_end');

            $table->index(['provider', 'status']);
        });

        Schema::table('subscription_addons', function (Blueprint $table) {
            $table->string('provider', 32)->default('manual')->after('user_id');
            $table->string('external_id', 64)->nullable()->unique()->after('provider');
            $table->boolean('cancel_at_period_end')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['provider', 'status']);
            $table->dropUnique(['external_id']);
            $table->dropColumn([
                'provider',
                'external_id',
                'external_price_id',
                'renews_at',
                'trial_ends_at',
                'canceled_at',
                'cancel_at_period_end',
                'paused_at',
            ]);
        });

        Schema::table('subscription_addons', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['provider', 'external_id', 'cancel_at_period_end']);
        });
    }
};
