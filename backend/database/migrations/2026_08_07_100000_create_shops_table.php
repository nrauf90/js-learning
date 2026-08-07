<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();

            // The shop admin who owns this shop. Staff accounts point at the
            // shop through users.shop_id rather than owning it.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('address', 255)->nullable();
            // Printed at the top of the till roll when set.
            $table->string('logo_path')->nullable();
            $table->string('receipt_footer', 255)->nullable();

            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
