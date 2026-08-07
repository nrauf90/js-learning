<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The till's day book. Sales no longer post into the cash ledger one by
     * one; instead the day is bracketed by an opening float and a closing
     * count, and those two are what reach cash flow.
     */
    public function up(): void
    {
        Schema::create('day_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();

            $table->date('business_date');

            $table->decimal('opening_amount', 14, 2)->default(0);
            $table->decimal('closing_amount', 14, 2)->nullable();

            // What the system says should be in the drawer at close, captured
            // when the day is closed so a later refund cannot rewrite history.
            $table->decimal('expected_amount', 14, 2)->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // The two cash_entries this day produced, so reopening or
            // correcting a day can find and amend them.
            $table->foreignId('opening_entry_id')->nullable()->constrained('cash_entries')->nullOnDelete();
            $table->foreignId('closing_entry_id')->nullable()->constrained('cash_entries')->nullOnDelete();

            $table->timestamps();

            // One day book per shop per day — the till opening twice must
            // reuse the existing row rather than start a second float.
            $table->unique(['user_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_balances');
    }
};
