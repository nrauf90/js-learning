<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per instalment paid against a supplier's invoice — the audit trail
     * behind `purchases.amount_paid`, and the mirror image of `sale_payments`.
     *
     * Until this existed a Rs 40,000 delivery paid off across the month could
     * only be recorded by typing each new figure over the last one, so the shop
     * kept the balance and lost every payment that made it.
     */
    public function up(): void
    {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();

            // The login that typed it in. Nullable so removing a staff account
            // never destroys the payment history it recorded.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('method', 16);
            $table->string('reference', 64)->nullable();

            // Who actually handed the money over. The owner types the entry up
            // in the evening; the boy at the counter paid the van driver at
            // noon — and it is his name the supplier will quote back.
            $table->string('paid_by_name', 120)->nullable();

            $table->string('note', 255)->nullable();
            $table->timestamp('paid_at');

            $table->timestamps();

            $table->index(['purchase_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
    }
};
