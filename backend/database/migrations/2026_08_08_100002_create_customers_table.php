<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The khata — the credit notebook by the till — as a table.
     *
     * Sales already carried `customer_name` and `customer_phone`, which is
     * enough to print on a slip but not enough to answer "who owes me what":
     * to a string comparison "Ali", "Ali bhai" and "Ali Raza" are three
     * different debtors. A row here is the person; the columns on the sale stay
     * as the snapshot of who it was rung up for.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Each shop keeps its own khata: two shops' "Ali Raza" are two
            // different men, and neither may see the other's balance.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('address', 255)->nullable();

            // Null is "no ceiling set", which is the normal case in a mohalla
            // store and is emphatically not the same as a limit of zero — that
            // would refuse the customer any credit at all.
            $table->decimal('credit_limit', 12, 2)->nullable();

            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'name']);
            // The till resolves a walk-in by number before it falls back to the
            // name, so this is a hot path, not a reporting convenience.
            $table->index(['user_id', 'phone']);
        });

        Schema::table('sales', function (Blueprint $table) {
            // nullOnDelete rather than cascade: deleting a khata page must not
            // delete the shop's takings. The name/phone snapshot above it is
            // what keeps the sale readable once the link is gone.
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            // "What is still open on this khata" — the query behind the
            // outstanding list, the statement and every payment allocation.
            $table->index(['customer_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'payment_status']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
