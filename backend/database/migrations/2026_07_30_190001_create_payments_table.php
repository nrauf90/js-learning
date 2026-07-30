<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('plan', 32);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('PKR');
            $table->string('status', 32)->default('pending');
            $table->string('txn_ref')->unique();
            $table->string('provider_reference')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
