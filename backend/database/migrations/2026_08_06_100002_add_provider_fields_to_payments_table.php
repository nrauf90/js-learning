<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Paddle transaction id (txn_…). `provider_reference` already held
            // the wallets' free-form reference; this is indexed separately
            // because webhooks correlate on it.
            $table->string('external_transaction_id', 64)->nullable()->unique()->after('provider_reference');
            $table->string('external_subscription_id', 64)->nullable()->after('external_transaction_id');
            $table->string('invoice_number', 64)->nullable()->after('external_subscription_id');

            // Set when an adjustment (refund/credit) lands against the
            // transaction. Kept as an amount rather than a flag so partial
            // refunds are representable.
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['external_transaction_id']);
            $table->dropColumn([
                'external_transaction_id',
                'external_subscription_id',
                'invoice_number',
                'refunded_amount',
            ]);
        });
    }
};
