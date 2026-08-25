<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\ReceiptImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Receipts and payment screenshots.
 *
 * Three things carry them. A purchase holds the wholesaler's bill, taken when
 * the delivery is booked in. A purchase payment holds the transfer screenshot
 * for the instalment that paid it. And a sale payment holds the same for money
 * taken over the counter — which is where the khata's "I paid you two thousand
 * last week" lands.
 *
 * Each is reached through its parent rather than by attachment id, so the shop
 * boundary is checked against a record the caller already had to be allowed to
 * see. What that check *is* differs by parent: see authorizeWrite().
 *
 * Reading is the exception: show() takes an attachment id because an <img> tag
 * can only carry a URL. It re-derives the parent and re-runs the same checks
 * before a byte leaves the disk.
 */
class AttachmentController extends Controller
{
    /**
     * A ceiling per record, not per shop.
     *
     * A long bill photographed in three pieces is normal; forty is somebody
     * using the invoice screen as a photo album, and the files are on our disk
     * with no quota behind them. High enough that no honest use hits it.
     */
    public const MAX_PER_RECORD = 12;

    public function __construct(private ReceiptImageStore $images) {}

    /* ------------------------------------------------------------- uploads */

    /**
     * Attach a photo of the supplier's bill to a delivery.
     *
     * Two authorisations, the same pair PurchaseController::storePayment() uses
     * and for the same reason: `view` is the shop boundary, `create` is the
     * catalogue permission that gates booking the delivery in. A till-only
     * clerk who may not receive stock may not file its paperwork either.
     */
    public function storeForPurchase(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);
        $this->authorize('create', Purchase::class);

        return $this->store($request, $purchase, ReceiptImageStore::PURCHASE_DIR, 'purchase-'.$purchase->id);
    }

    /**
     * Attach the transfer screenshot to one instalment.
     *
     * The payment is resolved through its purchase, not on its own: the shop
     * boundary lives on `purchases.user_id`, and `purchase_payments` has no
     * owner column of its own to check.
     */
    public function storeForPurchasePayment(Request $request, PurchasePayment $purchasePayment): JsonResponse
    {
        $purchase = $this->parentPurchase($purchasePayment);

        $this->authorize('view', $purchase);
        $this->authorize('create', Purchase::class);

        return $this->store($request, $purchasePayment, ReceiptImageStore::PAYMENT_DIR, 'payment-'.$purchasePayment->id);
    }

    /**
     * Attach the transfer screenshot to money taken against a customer's khata.
     *
     * Authorised differently from the purchase endpoints above, and the
     * difference is the point: booking stock in is catalogue work, so its
     * paperwork needs the catalogue permission. Collecting udhaar is till work —
     * the customer walks in and hands the notes to whoever is behind the counter
     * — so this asks only for `settle` on the parent sale, exactly what
     * recording the payment itself asks for. A cashier who may take the money
     * must be able to file the proof of it.
     *
     * One khata payment is spread across several sales, so it writes several
     * `sale_payments` rows. The picture goes on one of them (the caller is
     * handed every id and files it against the first) and
     * CustomerController::paymentHistory() gathers attachments back across the
     * group when the ledger is read.
     */
    public function storeForSalePayment(Request $request, SalePayment $salePayment): JsonResponse
    {
        $sale = $this->parentSale($salePayment);

        $this->authorize('settle', $sale);

        return $this->store($request, $salePayment, ReceiptImageStore::KHATA_DIR, 'khata-'.$salePayment->id);
    }

    /* --------------------------------------------------------------- reads */

    /**
     * Stream one attachment's bytes.
     *
     * Not a redirect to a public URL: these carry account titles, phone numbers
     * and transaction ids, and a public URL would be permanent, shareable and
     * unauthenticated. The file is read off the private `receipts` disk and
     * handed back inline, with the authorisation re-run first.
     *
     * `Content-Disposition: inline` with a server-chosen filename, and
     * `X-Content-Type-Options: nosniff` so a browser cannot be talked into
     * treating the bytes as anything other than the image type we verified on
     * the way in.
     */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorizeRead($attachment);

        $disk = Storage::disk($this->images->disk());

        abort_unless($disk->exists($attachment->path), 404, 'That file is no longer on file.');

        return $disk->response(
            $attachment->path,
            basename($attachment->path),
            [
                'Content-Type' => $attachment->mime,
                'X-Content-Type-Options' => 'nosniff',
                // Private, not public: a shared cache must never hold a
                // document that took an authorisation check to reach.
                'Cache-Control' => 'private, max-age=300',
            ],
            'inline'
        );
    }

    /* ------------------------------------------------------------- deletes */

    /**
     * Remove one attachment.
     *
     * Gated on exactly what uploading it was gated on — see authorizeWrite().
     * A clerk who could not file a receipt must not be able to destroy one
     * either.
     */
    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $this->authorizeRead($attachment);
        $this->authorizeWrite($attachment);

        $path = $attachment->path;

        // Row first, file second. The other order would leave a row pointing at
        // nothing if the delete failed halfway; this way the worst case is an
        // orphaned file, which costs disk and nothing else.
        $attachment->delete();
        $this->images->delete($path);

        return response()->json(['message' => 'Attachment removed.']);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The shared write path. `$directory` and `$prefix` are built by the
     * callers above from a row id — never from request input.
     */
    private function store(Request $request, Purchase|PurchasePayment|SalePayment $record, string $directory, string $prefix): JsonResponse
    {
        $validated = $request->validate([
            ...$this->images->rules(),
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertRoomFor($record);

        $ownerId = $request->user()->dataOwnerId();
        $file = $validated['image'];

        // The file lands before the row is written, so a rejected image never
        // leaves a row behind. If the insert then fails the file is orphaned,
        // which is the cheap direction to fail in.
        $path = $this->images->store($file, $directory, $prefix);

        try {
            $attachment = DB::transaction(function () use ($record, $ownerId, $request, $file, $path, $validated) {
                $attachment = new Attachment([
                    'uploaded_by' => $request->user()->id,
                    // Only ever displayed, never used to build a path.
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize() ?: 0,
                    'caption' => $validated['caption'] ?? null,
                ]);

                // Outside mass assignment on purpose — see the Attachment model.
                $attachment->forceFill([
                    'user_id' => $ownerId,
                    'path' => $path,
                ]);

                $record->attachments()->save($attachment);

                return $attachment;
            });
        } catch (\Throwable $e) {
            $this->images->delete($path);

            throw $e;
        }

        return response()->json(['attachment' => self::payload($attachment)], 201);
    }

    /**
     * The shop check for a read, derived from whatever the attachment hangs off
     * rather than trusted from the attachment row.
     *
     * A type this controller does not know how to authorise is refused, not
     * served: the default has to be refusal, or adding an attachable type later
     * becomes a silent leak.
     */
    private function authorizeRead(Attachment $attachment): void
    {
        $attachable = $attachment->attachable;

        $parent = match (true) {
            $attachable instanceof Purchase => $attachable,
            $attachable instanceof PurchasePayment => $this->parentPurchase($attachable),
            $attachable instanceof SalePayment => $this->parentSale($attachable),
            default => null,
        };

        abort_if($parent === null, 404);

        $this->authorize('view', $parent);
    }

    /**
     * What it takes to add to, or remove from, this record's paperwork.
     *
     * Filing is gated the same way recording the underlying entry is, because it
     * is the same job: the catalogue permission for a delivery and its bill, and
     * `settle` for money taken at the counter. Deleting asks for the same thing
     * — a clerk who could not file a receipt should not be able to destroy one
     * either.
     */
    private function authorizeWrite(Attachment $attachment): void
    {
        $attachable = $attachment->attachable;

        if ($attachable instanceof SalePayment) {
            $this->authorize('settle', $this->parentSale($attachable));

            return;
        }

        $this->authorize('create', Purchase::class);
    }

    private function parentPurchase(PurchasePayment $payment): Purchase
    {
        $purchase = $payment->purchase;

        abort_if($purchase === null, 404);

        return $purchase;
    }

    private function parentSale(SalePayment $payment): Sale
    {
        $sale = $payment->sale;

        abort_if($sale === null, 404);

        return $sale;
    }

    private function assertRoomFor(Purchase|PurchasePayment|SalePayment $record): void
    {
        if ($record->attachments()->count() >= self::MAX_PER_RECORD) {
            throw ValidationException::withMessages([
                'image' => ['This record already has '.self::MAX_PER_RECORD.' attachments. Remove one before adding another.'],
            ]);
        }
    }

    /**
     * How an attachment reads on screen.
     *
     * `url` is an API path rather than a storage URL, because that is what it
     * is: every fetch goes back through show() and is authorised again.
     *
     * @return array<string, mixed>
     */
    public static function payload(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => '/api/attachments/'.$attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size_bytes' => (int) $attachment->size_bytes,
            'caption' => $attachment->caption,
            'uploaded_by' => $attachment->uploaded_by,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }
}
