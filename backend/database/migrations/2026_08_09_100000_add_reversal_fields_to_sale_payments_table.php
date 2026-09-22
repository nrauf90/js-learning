<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A voided instalment is marked, never deleted.
     *
     * A mis-typed payment has to stay on the page it was typed onto: delete the
     * row and the khata stops explaining its own balance — "where did the
     * Rs 2,000 I wrote down go" is a worse question than "this line was taken
     * back". `reversed_at` is the stamp of when it was taken back and
     * `reversed_by_user_id` whose login did it; every total that reads this
     * table filters both out.
     */
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('paid_at');
            $table->foreignId('reversed_by_user_id')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by_user_id');
            $table->dropColumn('reversed_at');
        });
    }
};
