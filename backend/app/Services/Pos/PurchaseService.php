<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Unit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    /**
     * Book in a delivery.
     *
     * Everything happens in one transaction with the products row-locked, for
     * the same reason SaleService::create() does it: the count and the cost are
     * both read, arithmetic is done on them, and the result is written back.
     * Without the lock a sale rung up mid-delivery would read a stock figure
     * that the receipt was about to overwrite, and the shelf count would lose
     * whichever write landed second.
     *
     * Quantities and costs arrive quoted in each product's own `price_unit` —
     * the shop buys "50 kg at Rs 180/kg" — and are converted to base units once,
     * here, before anything is stored.
     *
     * @param  array{
     *     items: list<array{product_id: int, quantity: float, unit_cost: float}>,
     *     supplier_id?: int|null,
     *     invoice_number?: string|null,
     *     purchase_date?: string|null,
     *     discount_amount?: float|null,
     *     amount_paid?: float|null,
     *     deposit_method?: string|null,
     *     paid_by_name?: string|null,
     *     note?: string|null,
     * }  $data
     */
    public function create(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data) {
            // Staff book stock in against their shop owner's catalogue, and the
            // bill is the owner's to settle — not the receiving clerk's.
            $ownerId = $user->dataOwnerId();

            $lines = $this->resolveLines($ownerId, $data['items']);

            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the subtotal.'],
                ]);
            }

            $total = round($subtotal - $discount, 2);
            $paid = round((float) ($data['amount_paid'] ?? 0), 2);

            // Paying more than the bill would leave the wholesaler owing the
            // shop, which is a credit note, not a purchase.
            if ($paid > $total) {
                throw ValidationException::withMessages([
                    'amount_paid' => ['The amount paid cannot be more than the purchase total.'],
                ]);
            }

            $purchase = Purchase::create([
                'user_id' => $ownerId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'purchase_date' => isset($data['purchase_date'])
                    ? Carbon::parse($data['purchase_date'])->toDateString()
                    : now()->toDateString(),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => $total,
                'amount_paid' => 0,
                'note' => $data['note'] ?? null,
            ]);

            // Derived from the row id rather than a per-user counter, so it is
            // unique and ordered without a second query to race on.
            $purchase->forceFill([
                'reference' => 'P-'.str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT),
            ])->save();

            // The deposit handed to the van driver is the first instalment, so
            // it belongs in the same history as everything paid later —
            // otherwise `amount_paid` could not be reconstructed from
            // `purchase_payments` and the invoice would open showing a paid
            // figure with nothing behind it.
            if ($paid > 0) {
                $purchase->payments()->create([
                    'recorded_by' => $user->id,
                    'amount' => $paid,
                    'method' => $data['deposit_method'] ?? 'cash',
                    'paid_by_name' => $data['paid_by_name'] ?? null,
                    'note' => 'Paid on delivery',
                    'paid_at' => now(),
                ]);
            }

            $this->syncPaymentTotals($purchase);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $purchase->items()->create([
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit_type' => $product->unit_type,
                    'base_unit' => $product->base_unit,
                    'price_unit' => $product->price_unit,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                ]);

                $this->receive($product, $line['quantity'], $line['unit_cost'], $purchase->reference);
            }

            return $purchase->fresh(['items', 'supplier', 'payments']);
        });
    }

    /**
     * Pay something off a supplier's invoice.
     *
     * The mirror of SalePaymentService::settle(): a kiryana shop takes a
     * Rs 40,000 delivery, pays Rs 15,000 to the driver and clears the rest over
     * the month, and each of those payments has to survive as its own row.
     *
     * The purchase is locked for the whole transaction for the same reason a
     * sale is: the owner settling the bill on his phone while the clerk settles
     * it at the counter would otherwise both read the same outstanding balance,
     * both pass the over-payment check, and the shop would pay twice.
     *
     * @param  array{
     *     amount: float|string,
     *     method: string,
     *     reference?: string|null,
     *     paid_by_name?: string|null,
     *     note?: string|null,
     * }  $data
     */
    public function recordPayment(User $actor, Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($actor, $purchase, $data) {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            // Rounded before it is compared to the outstanding balance, which is
            // itself rounded — otherwise a 0.005 of float drift leaves an
            // invoice the shop can never quite clear.
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter an amount greater than zero.'],
                ]);
            }

            $outstanding = $locked->outstandingAmount();

            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['This invoice is already settled in full.'],
                ]);
            }

            // Refused rather than carried as a credit with the wholesaler: this
            // app has nowhere to hold money a supplier owes back, so absorbing
            // the difference would simply lose it.
            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => ['Only Rs '.number_format($outstanding, 2).' is outstanding on this invoice.'],
                ]);
            }

            $locked->payments()->create([
                // The login that typed it, which is often not the person who
                // handed the cash over — that is what `paid_by_name` is for.
                'recorded_by' => $actor->id,
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_by_name' => $data['paid_by_name'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => now(),
            ]);

            $this->syncPaymentTotals($locked);

            return $locked->fresh(['items', 'supplier', 'payments']);
        });
    }

    /**
     * Re-derive `amount_paid` and `payment_status` from the payment rows.
     *
     * Summed rather than incremented on purpose. A running total that is added
     * to drifts the first moment anything goes wrong — a retried request, a row
     * removed by hand — and once it has drifted there is nothing left to pull it
     * back. Recomputing from the history means the invoice can only ever say
     * what its own payments say.
     *
     * Must be called inside the caller's transaction, with the purchase locked.
     */
    private function syncPaymentTotals(Purchase $purchase): void
    {
        $purchase->forceFill([
            'amount_paid' => round((float) $purchase->payments()->sum('amount'), 2),
        ]);

        $purchase->forceFill(['payment_status' => $purchase->resolvePaymentStatus()])->save();
    }

    /**
     * Put the goods on the shelf and reprice what they cost.
     *
     * @param  string  $reference  the purchase this delivery came in on, for the audit trail
     */
    private function receive(Product $product, float $quantity, float $unitCost, string $reference): void
    {
        $product->cost = $this->weightedAverageCost($product, $quantity, $unitCost);

        // Untracked goods have no count to raise — but the shop still paid this
        // price for them, so the cost is saved either way or the margin on a
        // loose-count item would stay stuck at whatever was first typed in.
        if (! $product->track_stock) {
            $product->save();

            return;
        }

        // Rounded on every write for the same reason SaleService does it:
        // unrounded float addition leaves the count creeping away from the
        // shelf with nothing to pull it back.
        $product->stock_quantity = round((float) $product->stock_quantity + $quantity, Unit::QUANTITY_DP);
        $product->save();

        StockMovement::create([
            'user_id' => $product->user_id,
            'product_id' => $product->id,
            'type' => 'restock',
            'quantity_delta' => $quantity,
            'balance_after' => $product->stock_quantity,
            'note' => 'Stock in '.$reference,
        ]);
    }

    /**
     * Blend the new cost into the old rather than replacing it.
     *
     * Ghee and atta move in price every month in Pakistan, and a shop is almost
     * never selling only the newest sack. Overwriting the cost the moment a
     * delivery lands would price the twenty kilos already on the shelf at
     * whatever the last van charged: a price rise makes the shop look like it
     * is losing money on stock it bought cheaply, and a price drop invents a
     * margin that was never earned. Weighting by quantity means the recorded
     * cost always describes the goods actually standing in the shop.
     *
     * With nothing (or less than nothing, after an offline sale overdrew the
     * count) left to blend against, there is no old stock to protect and the
     * new cost simply becomes the cost.
     */
    private function weightedAverageCost(Product $product, float $quantity, float $unitCost): float
    {
        $oldQuantity = (float) $product->stock_quantity;
        $oldCost = $product->cost === null ? null : (float) $product->cost;

        if ($oldCost === null || $oldQuantity <= 0 || ! $product->track_stock) {
            return round($unitCost, Unit::PRICE_DP);
        }

        return round(
            (($oldQuantity * $oldCost) + ($quantity * $unitCost)) / ($oldQuantity + $quantity),
            Unit::PRICE_DP
        );
    }

    /**
     * Products locked, quantities and costs converted out of the unit the shop
     * quotes in and into the base unit everything downstream works in.
     *
     * @param  list<array{product_id: int, quantity: float, unit_cost: float}>  $items
     * @return list<array{product: Product, quantity: float, unit_cost: float, line_total: float}>
     */
    private function resolveLines(int $ownerId, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one item to the purchase.'],
            ]);
        }

        $products = Product::query()
            ->where('user_id', $ownerId)
            ->whereIn('id', array_map(fn (array $item) => (int) $item['product_id'], $items))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lines = [];
        $errors = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $product = $products->get($productId);

            if (! $product) {
                $errors['items'][] = "Product #{$productId} was not found.";

                continue;
            }

            $quantity = Unit::toBase((float) $item['quantity'], $product->price_unit);

            // A line that rounds away to nothing — a scale that read zero, or a
            // quantity typed as text — would book a delivery of nothing and
            // still drag the weighted average towards its cost.
            if ($quantity <= 0) {
                $errors['items'][] = "{$product->name}: enter a quantity greater than zero.";

                continue;
            }

            $unitCost = Unit::priceToBase((float) $item['unit_cost'], $product->price_unit);

            $lines[] = [
                // The same instance every time the product appears, so two lines
                // for one product blend into each other's average rather than
                // each averaging against the count as it stood before the van
                // arrived.
                'product' => $product,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => round($unitCost * $quantity, 2),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
