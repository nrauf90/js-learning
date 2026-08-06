<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Assigned from the row id straight after insert, so it is unique
            // and monotonic without a per-user counter to race on. Nullable
            // only for that gap between insert and update.
            $table->string('reference', 32)->nullable()->unique();

            // The income cash_entry this sale posted. Nullable because a
            // refunded sale has its entry removed, and because a sale must
            // still be recoverable if entry creation ever fails.
            $table->foreignId('cash_entry_id')->nullable()->constrained('cash_entries')->nullOnDelete();

            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->string('payment_method', 16)->default('cash');
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->default(0);

            // completed | refunded
            $table->string('status', 16)->default('completed');
            $table->timestamp('refunded_at')->nullable();

            $table->timestamp('sold_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sold_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
