<?php

namespace App\Services\Pos;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    /** Slug of the shared income category every POS sale posts against. */
    public const SALES_CATEGORY_SLUG = 'sales';

    /**
     * Ring up a sale.
     *
     * Everything happens in one transaction with the products row-locked. Two
     * cashiers selling the last unit at the same moment is the normal failure
     * mode for a POS, and without the lock both reads would see stock of 1 and
     * both writes would succeed, leaving -1 on the shelf.
     *
     * @param  array{
     *     items: list<array{product_id: int, quantity: int}>,
     *     discount_amount?: float|null,
     *     payment_method?: string|null,
     *     amount_tendered?: float|null,
     *     note?: string|null,
     *     client_uuid?: string|null,
     *     offline?: bool|null,
     *     sold_at?: string|null,
     * }  $data
     */
    public function create(User $user, array $data): Sale
    {
        return DB::transaction(function () use ($user, $data) {
            $isOffline = (bool) ($data['offline'] ?? false);

            // A sale rung up offline has already happened — the goods left the
            // shop and the money changed hands. Rejecting it on sync because
            // another terminal sold the same last unit would lose a real
            // transaction, so stock is allowed to go negative here and the
            // negative count becomes the signal to recount.
            $lines = $this->resolveLines($user, $data['items'], allowNegativeStock: $isOffline);

            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the subtotal.'],
                ]);
            }

            $total = round($subtotal - $discount, 2);
            $method = $data['payment_method'] ?? 'cash';
            $tendered = isset($data['amount_tendered']) ? round((float) $data['amount_tendered'], 2) : null;
            $change = 0.0;

            if ($method === 'cash' && $tendered !== null) {
                if ($tendered < $total) {
                    throw ValidationException::withMessages([
                        'amount_tendered' => ['Amount tendered is less than the total due.'],
                    ]);
                }
                $change = round($tendered - $total, 2);
            }

            $sale = Sale::create([
                'user_id' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
                'is_offline' => $isOffline,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => $total,
                'payment_method' => $method,
                'amount_tendered' => $tendered,
                'change_due' => $change,
                'status' => 'completed',
                // Offline sales carry the time they were actually rung up, not
                // the time the queue happened to drain.
                'sold_at' => isset($data['sold_at']) ? Carbon::parse($data['sold_at']) : now(),
                'note' => $data['note'] ?? null,
            ]);

            // Derived from the row id rather than a per-user counter, so it is
            // unique and ordered without a second query to race on.
            $sale->forceFill(['reference' => 'S-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT)])->save();

            foreach ($lines as $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'name' => $line['product']->name,
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $line['product']->cost,
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                $this->applyStockDelta($line['product'], -$line['quantity'], 'sale', $sale->id);
            }

            $this->attachCashEntry($user, $sale);

            return $sale->fresh(['items']);
        });
    }

    /**
     * Reverse a sale in whole or in part.
     *
     * Stock goes back on the shelf and the income entry shrinks by the refunded
     * amount, so reports and the dashboard never count money that was handed
     * back. The entry is reduced rather than offset with a negative row because
     * `cash_entries.amount` is a positive-only column everywhere else in the
     * app; a fully refunded sale deletes it outright.
     *
     * @param  list<array{sale_item_id: int, quantity: int}>|null  $items
     *                                                                    Null refunds everything still outstanding.
     */
    public function refund(Sale $sale, ?array $items = null): Sale
    {
        return DB::transaction(function () use ($sale, $items) {
            if ($sale->isRefunded()) {
                throw ValidationException::withMessages([
                    'sale' => ['This sale has already been refunded.'],
                ]);
            }

            $sale->load('items');
            $requested = $this->resolveRefundQuantities($sale, $items);

            if ($requested === []) {
                throw ValidationException::withMessages([
                    'items' => ['Nothing left to refund on this sale.'],
                ]);
            }

            $grossRefund = 0.0;

            foreach ($requested as $saleItemId => $quantity) {
                /** @var SaleItem $item */
                $item = $sale->items->firstWhere('id', $saleItemId);

                $grossRefund += (float) $item->unit_price * $quantity;

                if ($item->product_id) {
                    $product = Product::query()->lockForUpdate()->find($item->product_id);

                    if ($product) {
                        $this->applyStockDelta($product, $quantity, 'refund', $sale->id);
                    }
                }

                $item->forceFill(['refunded_quantity' => $item->refunded_quantity + $quantity])->save();
            }

            // A ticket-level discount belongs proportionally to every line, so
            // a partial refund returns the discounted share, not list price.
            $ratio = (float) $sale->subtotal > 0 ? (float) $sale->total / (float) $sale->subtotal : 0.0;
            $refundAmount = min(round($grossRefund * $ratio, 2), $sale->refundableTotal());

            $sale->load('items');
            $fullyRefunded = $sale->items->every(fn (SaleItem $item) => $item->remainingQuantity() === 0);

            // Absorb any rounding drift from line-by-line refunds into the last
            // one, so a fully refunded sale nets to exactly zero.
            $refundedTotal = $fullyRefunded
                ? (float) $sale->total
                : round((float) $sale->refunded_amount + $refundAmount, 2);

            $this->reduceCashEntry($sale, round($refundedTotal - (float) $sale->refunded_amount, 2), $fullyRefunded);

            $sale->forceFill([
                'status' => $fullyRefunded ? 'refunded' : 'partially_refunded',
                'refunded_amount' => $refundedTotal,
                'refunded_at' => now(),
            ])->save();

            return $sale->fresh(['items']);
        });
    }

    /**
     * Manual stock correction from the products screen — a delivery arrived, or
     * a count came up short.
     */
    public function adjustStock(User $user, Product $product, int $delta, string $type, ?string $note = null): Product
    {
        return DB::transaction(function () use ($product, $delta, $type, $note) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->stock_quantity + $delta < 0) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['Adjustment would take stock below zero.'],
                ]);
            }

            $this->applyStockDelta($locked, $delta, $type, null, $note);

            return $locked->fresh();
        });
    }

    /**
     * Map the requested refund to {sale_item_id: quantity}, clamped to what is
     * actually still outstanding on each line.
     *
     * @param  list<array{sale_item_id: int, quantity: int}>|null  $items
     * @return array<int, int>
     */
    private function resolveRefundQuantities(Sale $sale, ?array $items): array
    {
        if ($items === null) {
            return $sale->items
                ->filter(fn (SaleItem $item) => $item->remainingQuantity() > 0)
                ->mapWithKeys(fn (SaleItem $item) => [$item->id => $item->remainingQuantity()])
                ->all();
        }

        $resolved = [];

        foreach ($items as $row) {
            $itemId = (int) $row['sale_item_id'];
            $quantity = (int) $row['quantity'];
            $item = $sale->items->firstWhere('id', $itemId);

            if (! $item) {
                throw ValidationException::withMessages([
                    'items' => ["Line #{$itemId} is not part of this sale."],
                ]);
            }

            if ($quantity < 1 || $quantity > $item->remainingQuantity()) {
                throw ValidationException::withMessages([
                    'items' => ["{$item->name}: only {$item->remainingQuantity()} left to refund."],
                ]);
            }

            $resolved[$itemId] = ($resolved[$itemId] ?? 0) + $quantity;

            if ($resolved[$itemId] > $item->remainingQuantity()) {
                throw ValidationException::withMessages([
                    'items' => ["{$item->name}: only {$item->remainingQuantity()} left to refund."],
                ]);
            }
        }

        return $resolved;
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return list<array{product: Product, quantity: int, unit_price: float, line_total: float}>
     */
    private function resolveLines(User $user, array $items, bool $allowNegativeStock = false): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one item to the sale.'],
            ]);
        }

        // Merge duplicates first: scanning the same barcode three times must
        // check stock once against a quantity of 3, not three times against 1.
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }

        $products = Product::query()
            ->where('user_id', $user->id)
            ->whereIn('id', array_keys($quantities))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lines = [];
        $errors = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                $errors['items'][] = "Product #{$productId} was not found.";

                continue;
            }

            // Ownership and existence are always enforced. Stock and the active
            // flag are not, for offline replays: the sale is already history.
            if (! $allowNegativeStock) {
                if (! $product->is_active) {
                    $errors['items'][] = "{$product->name} is no longer available.";

                    continue;
                }

                if (! $product->hasStockFor($quantity)) {
                    $errors['items'][] = "{$product->name}: only {$product->stock_quantity} left in stock.";

                    continue;
                }
            }

            $unitPrice = (float) $product->price;

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    private function applyStockDelta(
        Product $product,
        int $delta,
        string $type,
        ?int $saleId = null,
        ?string $note = null,
    ): void {
        if (! $product->track_stock) {
            return;
        }

        $product->stock_quantity += $delta;
        $product->save();

        StockMovement::create([
            'user_id' => $product->user_id,
            'product_id' => $product->id,
            'sale_id' => $saleId,
            'type' => $type,
            'quantity_delta' => $delta,
            'balance_after' => $product->stock_quantity,
            'note' => $note,
        ]);
    }

    /**
     * Post the sale into the existing cash ledger, so the dashboard, reports
     * and CSV exports pick it up with no POS-specific wiring.
     */
    private function attachCashEntry(User $user, Sale $sale): void
    {
        if ((float) $sale->total <= 0) {
            // A fully discounted sale still happened, but there is no income to
            // record and cash_entries requires a positive amount.
            return;
        }

        $entry = CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $this->salesCategory()->id,
            'type' => 'income',
            'amount' => $sale->total,
            'entry_date' => $sale->sold_at->toDateString(),
            'note' => 'POS sale '.$sale->reference,
        ]);

        $sale->forceFill(['cash_entry_id' => $entry->id])->save();
    }

    private function reduceCashEntry(Sale $sale, float $refundAmount, bool $fullyRefunded): void
    {
        $entry = $sale->cashEntry;

        if (! $entry) {
            return;
        }

        $remaining = round((float) $entry->amount - $refundAmount, 2);

        if ($fullyRefunded || $remaining <= 0) {
            $entry->delete();
            $sale->forceFill(['cash_entry_id' => null])->save();

            return;
        }

        $entry->update(['amount' => $remaining]);
    }

    private function salesCategory(): ExpenseCategory
    {
        // firstOrCreate rather than a plain lookup: the seeder adds this, but a
        // sale must not fail on a database that was migrated without seeding.
        return ExpenseCategory::query()->firstOrCreate(
            ['slug' => self::SALES_CATEGORY_SLUG],
            [
                'name' => 'Sales',
                'kind' => 'income',
                'icon' => null,
                'is_system' => true,
            ],
        );
    }
}
