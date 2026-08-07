<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed what, when. A shop admin handing catalogue access to staff
     * needs to be able to answer "who dropped the price on this" afterwards,
     * so `changes` keeps the before/after of every field that actually moved.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Nullable so removing a staff account leaves the trail intact —
            // the whole point is that history survives the person.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised so the report still names the actor after the
            // account is gone.
            $table->string('user_name', 160)->nullable();

            // created | updated | deleted | stock_adjusted | ...
            $table->string('action', 32);

            // Short class name, e.g. "Product" / "ProductCategory".
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            // Human label captured at write time, so a deleted product still
            // reads as its name rather than a bare id.
            $table->string('subject_label', 191)->nullable();

            // { field: { from: ..., to: ... } } — only fields that changed.
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['shop_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
