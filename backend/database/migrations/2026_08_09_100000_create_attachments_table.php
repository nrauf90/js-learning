<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The paperwork behind money that moved.
     *
     * Two things attach here today: the wholesaler's bill photographed when a
     * delivery is booked in, and the JazzCash/EasyPaisa/bank screenshot taken
     * when an instalment is paid against it. Until now neither had anywhere to
     * live, so "did we actually pay this?" came down to whether the shopkeeper
     * still had the paper — and a supplier disputing an instalment could only
     * be answered from memory.
     *
     * Polymorphic rather than `purchases.receipt_path` + a second column on
     * `purchase_payments`, for two reasons: an invoice routinely arrives as
     * several photographs (a long bill, front and back), and sale payments and
     * customer khata settlements are the same problem wearing a different hat.
     * A one-to-one column would have to be widened twice more.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // The shop, resolved through User::dataOwnerId() at write time.
            // Every read re-checks this before a byte is served, so a stale or
            // guessed attachment id cannot cross the shop boundary.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The login that uploaded it. Nullable so deactivating a staff
            // account never destroys the evidence they filed.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->morphs('attachable');

            // Relative to the `receipts` disk (storage/app/receipts), which is
            // private. Never a URL: nothing here is reachable without going
            // through AttachmentController.
            $table->string('path', 255);

            // What the uploader called it, kept only to show on screen and to
            // name the file if it is ever downloaded. It is not what the file
            // is stored as — see ImageStore::store().
            $table->string('original_name', 255)->nullable();

            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');

            // What the shopkeeper wrote on it: "Bilal paid the van driver",
            // "second half, cleared Friday".
            $table->string('caption', 255)->nullable();

            $table->timestamps();

            // morphs() already indexes (attachable_type, attachable_id) — the
            // list-for-a-record query, which is every read but the stream.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
