<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);

            // Paddle's event_id (evt_…). Unique across providers via the
            // composite index below — this is the dedup key. Paddle retries
            // delivery on any non-2xx, so the same event will arrive again.
            $table->string('event_id', 128);
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
