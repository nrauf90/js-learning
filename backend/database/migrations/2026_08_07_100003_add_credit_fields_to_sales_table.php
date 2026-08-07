<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // paid | partial | pending. Separate from `status`, which tracks
            // the goods (completed/refunded) — a sale can be fully delivered
            // and still unpaid, or refunded while a credit balance is open.
            $table->string('payment_status', 16)->default('paid')->after('status');

            // What has actually been collected so far. The outstanding balance
            // is derived from total - refunded_amount - paid_amount rather
            // than stored, so the two can never drift apart.
            $table->decimal('paid_amount', 12, 2)->default(0)->after('payment_status');

            // Who owes the money — needed the moment a sale goes out on credit.
            $table->string('customer_name', 120)->nullable()->after('paid_amount');
            $table->string('customer_phone', 32)->nullable()->after('customer_name');

            // Transaction id for the wallet/bank transfer that settled the
            // sale at the till (per-instalment references live on sale_payments).
            $table->string('payment_reference', 64)->nullable()->after('payment_method');

            $table->index(['user_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'payment_status']);
            $table->dropColumn([
                'payment_status',
                'paid_amount',
                'customer_name',
                'customer_phone',
                'payment_reference',
            ]);
        });
    }
};
