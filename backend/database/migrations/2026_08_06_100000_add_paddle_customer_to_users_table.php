<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Paddle customer id (ctm_…). Created lazily on first checkout so
            // repeat purchases and the customer portal resolve to one customer
            // rather than a new one per transaction.
            $table->string('paddle_customer_id', 64)->nullable()->unique()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['paddle_customer_id']);
            $table->dropColumn('paddle_customer_id');
        });
    }
};
