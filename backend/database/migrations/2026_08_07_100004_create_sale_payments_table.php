<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per instalment against a sale — the audit trail behind
     * `sales.paid_amount`. A credit sale settled in three visits leaves three
     * rows here, each with how much came in, how, and who took it.
     */
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // The till operator who took the money. Nullable so deleting a
            // staff account never destroys the payment history.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('method', 16);
            $table->string('reference', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('paid_at');

            $table->timestamps();

            $table->index(['sale_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
