<?php

namespace App\Services\Pos;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Unit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    /**
     * Ring up a sale.
     *
     * Everything happens in one transaction with the products row-locked. Two
     * cashiers selling the last unit at the same moment is the normal failure
     * mode for a POS, and without the lock both reads would see stock of 1 and
     * both writes would succeed, leaving -1 on the shelf.
     *
     * @param  array{
     *     items: list<array{product_id: int, quantity: float}>,
     *     discount_amount?: float|null,
     *     payment_method?: string|null,
     *     payment_reference?: string|null,
     *     paid_amount?: float|null,
     *     deposit_method?: string|null,
     *     received_by_name?: string|null,
     *     customer_name?: string|null,
     *     customer_phone?: string|null,
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

            // Staff ring up sales against their shop owner's catalogue and the
            // takings belong to that owner, not to the till operator.
            $ownerId = $user->dataOwnerId();

            // A sale rung up offline has already happened — the goods left the
            // shop and the money changed hands. Rejecting it on sync because
            // another terminal sold the same last unit would lose a real
            // transaction, so stock is allowed to go negative here and the
            // negative count becomes the signal to recount.
            $lines = $this->resolveLines($ownerId, $data['items'], allowNegativeStock: $isOffline);

            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the subtotal.'],
                ]);
            }

            // Settled in whole rupees: paisa coins do not circulate here, so a
            // ticket of Rs 62.50 is paid as Rs 63 and a till that insists on the
            // half-rupee is a till whose drawer never reconciles. The remainder
            // is recorded rather than dropped, so the receipt still adds up.
            $exactTotal = round($subtotal - $discount, 2);
            $total = round($exactTotal);
            $rounding = round($total - $exactTotal, 2);

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

            // Credit is the only method that leaves the till without the money;
            // every other one settles in full at the counter.
            $paid = $method === 'credit'
                ? $this->resolveCreditDeposit($data, $total)
                : $total;

            // Only a debt needs a khata page. A cash sale rung up under a name
            // is just a named receipt — there is nothing to chase, so opening a
            // customer for it would fill the khata with people who owe nothing.
            $customer = $method === 'credit'
                ? $this->resolveCustomer($ownerId, (string) $data['customer_name'], $data['customer_phone'] ?? null)
                : null;

            // Checked here, before a single row is written: the transaction
            // rolls back, so a refused sale moves no stock and records no debt.
            //
            // Skipped for offline replays for the same reason stock is allowed
            // to go negative above — the goods already left the shop and the
            // debt is already real. Refusing it on sync would not un-give the
            // credit, it would only lose the record of it.
            if ($customer && ! $isOffline) {
                $this->assertWithinCreditLimit($customer, round($total - $paid, 2));
            }

            $sale = Sale::create([
                'user_id' => $ownerId,
                'customer_id' => $customer?->id,
                'client_uuid' => $data['client_uuid'] ?? null,
                'is_offline' => $isOffline,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'rounding_adjustment' => $rounding,
                'total' => $total,
                'payment_method' => $method,
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_amount' => $paid,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'amount_tendered' => $tendered,
                'change_due' => $change,
                // The goods went out either way — `status` tracks the stock and
                // `payment_status` tracks the money, so a credit sale is a
                // completed sale that happens to still be owed for.
                'status' => 'completed',
                // Offline sales carry the time they were actually rung up, not
                // the time the queue happened to drain.
                'sold_at' => isset($data['sold_at']) ? Carbon::parse($data['sold_at']) : now(),
                'note' => $data['note'] ?? null,
            ]);

            // Derived from the row id rather than a per-user counter, so it is
            // unique and ordered without a second query to race on.
            $sale->forceFill([
                'reference' => 'S-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT),
                'payment_status' => $sale->resolvePaymentStatus(),
            ])->save();

            // A deposit taken at the till is the first instalment, so it belongs
            // in the same history as the ones collected later — otherwise
            // `paid_amount` would not add up from `sale_payments`. Sales settled
            // in full at the counter get no row: the sale itself is the record.
            if ($method === 'credit' && $paid > 0) {
                $sale->payments()->create([
                    'recorded_by' => $user->id,
                    // Defaults to nobody rather than to the logged-in cashier:
                    // a guessed name on a disputed payment is worse than a
                    // blank one, because it reads as a record of who took it.
                    'received_by_name' => filled($data['received_by_name'] ?? null)
                        ? trim((string) $data['received_by_name'])
                        : null,
                    'amount' => $paid,
                    'method' => $data['deposit_method'] ?? 'cash',
                    'reference' => $data['payment_reference'] ?? null,
                    'note' => 'Deposit taken at the till',
                    'paid_at' => $sale->sold_at,
                ]);
            }

            foreach ($lines as $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'name' => $line['product']->name,
                    'unit_type' => $line['product']->unit_type,
                    'base_unit' => $line['product']->base_unit,
                    'price_unit' => $line['product']->price_unit,
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $line['product']->cost,
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                $this->applyStockDelta($line['product'], -$line['quantity'], 'sale', $sale->id);
            }

            return $sale->fresh(['items', 'payments']);
        });
    }

    /**
     * Reverse a sale in whole or in part.
     *
     * Stock goes back on the shelf and the refunded amount is recorded on the
     * sale. Nothing is written to the cash ledger: individual sales no longer
     * post there, so there is no income entry to shrink either.
     *
     * @param  list<array{sale_item_id: int, quantity: float}>|null  $items
     *                                                                       Null refunds everything still outstanding.
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

                $item->forceFill([
                    'refunded_quantity' => round((float) $item->refunded_quantity + $quantity, Unit::QUANTITY_DP),
                ])->save();
            }

            // A ticket-level discount belongs proportionally to every line, so
            // a partial refund returns the discounted share, not list price.
            $ratio = (float) $sale->subtotal > 0 ? (float) $sale->total / (float) $sale->subtotal : 0.0;
            $refundAmount = min(round($grossRefund * $ratio, 2), $sale->refundableTotal());

            $sale->load('items');
            $fullyRefunded = $sale->items->every(fn (SaleItem $item) => $item->remainingQuantity() <= 0);

            // Absorb any rounding drift from line-by-line refunds into the last
            // one, so a fully refunded sale nets to exactly zero.
            $refundedTotal = $fullyRefunded
                ? (float) $sale->total
                : round((float) $sale->refunded_amount + $refundAmount, 2);

            $sale->forceFill([
                'status' => $fullyRefunded ? 'refunded' : 'partially_refunded',
                'refunded_amount' => $refundedTotal,
                'refunded_at' => now(),
            ]);

            // Goods that came back are no longer collectable, so an open credit
            // balance shrinks with the refund instead of chasing the customer
            // for stock they already returned.
            $sale->forceFill(['payment_status' => $sale->resolvePaymentStatus()])->save();

            return $sale->fresh(['items', 'payments']);
        });
    }

    /**
     * Manual stock correction from the products screen — a delivery arrived, or
     * a count came up short.
     */
    public function adjustStock(User $user, Product $product, float $delta, string $type, ?string $note = null): Product
    {
        return DB::transaction(function () use ($product, $delta, $type, $note) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            // Rounded before the test so writing off the exact remaining stock
            // is not read as a hair below zero and refused.
            if (round((float) $locked->stock_quantity + $delta, Unit::QUANTITY_DP) < 0) {
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
     * @param  list<array{sale_item_id: int, quantity: float}>|null  $items
     * @return array<int, float>
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
            $quantity = round((float) $row['quantity'], Unit::QUANTITY_DP);
            $item = $sale->items->firstWhere('id', $itemId);

            if (! $item) {
                throw ValidationException::withMessages([
                    'items' => ["Line #{$itemId} is not part of this sale."],
                ]);
            }

            // The tolerance is what lets "return the lot" through on a 250 g
            // line: the till sends back its own float, which need not match the
            // stored decimal to the last bit.
            if ($quantity <= 0 || $quantity > $item->remainingQuantity() + 0.0005) {
                throw ValidationException::withMessages([
                    'items' => [$this->refundLimitMessage($item)],
                ]);
            }

            $resolved[$itemId] = round(($resolved[$itemId] ?? 0.0) + $quantity, Unit::QUANTITY_DP);

            if ($resolved[$itemId] > $item->remainingQuantity() + 0.0005) {
                throw ValidationException::withMessages([
                    'items' => [$this->refundLimitMessage($item)],
                ]);
            }
        }

        return $resolved;
    }

    /**
     * A cashier reads "only 250 g left to refund", never "only 0.25", so the
     * remainder is spelled in the unit the line was actually sold in.
     */
    private function refundLimitMessage(SaleItem $item): string
    {
        $left = Unit::formatQuantity($item->remainingQuantity(), $item->unit_type);

        return "{$item->name}: only {$left} left to refund.";
    }

    /**
     * How much of a credit sale was handed over at the counter. Anything more
     * than the total would leave the shop owing the customer, which is a
     * refund, not a deposit.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCreditDeposit(array $data, float $total): float
    {
        // An unnamed debt is uncollectable — there is nobody to chase.
        if (blank($data['customer_name'] ?? null)) {
            throw ValidationException::withMessages([
                'customer_name' => ['A credit sale needs the customer name.'],
            ]);
        }

        $paid = round((float) ($data['paid_amount'] ?? 0), 2);

        if ($paid > $total) {
            throw ValidationException::withMessages([
                'paid_amount' => ['The deposit cannot be more than the sale total.'],
            ]);
        }

        return $paid;
    }

    /**
     * Find the khata page this credit sale belongs on, opening one if the
     * customer is new.
     *
     * The cashier's flow does not change for this: they type the name and
     * number they always typed, and the page is resolved behind them.
     *
     * Matching is deliberately conservative. A phone number is the only thing a
     * shop can really identify a person by, so it wins outright; a bare name is
     * only allowed to claim a page that has no number on it yet. Merging "Ali"
     * (0300…) with "Ali" (0345…) would put one man's debt on another man's
     * page, which is far worse than two rows the owner can tidy up by hand.
     */
    private function resolveCustomer(int $ownerId, string $name, ?string $phone): Customer
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $phone = filled($phone) ? trim($phone) : null;

        if ($phone !== null) {
            $byPhone = Customer::query()
                ->where('user_id', $ownerId)
                ->where('phone', $phone)
                ->first();

            if ($byPhone) {
                return $byPhone;
            }
        }

        $byName = Customer::query()
            ->where('user_id', $ownerId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($phone !== null, fn ($query) => $query->whereNull('phone'))
            ->first();

        if ($byName) {
            // The page learns the number the first time one is given.
            if ($phone !== null) {
                $byName->forceFill(['phone' => $phone])->save();
            }

            return $byName;
        }

        return Customer::create([
            'user_id' => $ownerId,
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    /**
     * A limit the shopkeeper wrote down is a decision, not a hint, so the sale
     * is refused rather than flagged. No limit set means no ceiling — most
     * pages in a mohalla khata never carry one.
     *
     * The message names the figures because "credit limit exceeded" tells the
     * person at the counter nothing they can act on: they need to know how much
     * to collect before the goods can go out.
     */
    private function assertWithinCreditLimit(Customer $customer, float $newDebt): void
    {
        if ($customer->credit_limit === null || $newDebt <= 0) {
            return;
        }

        $owed = $customer->outstandingBalance();
        $after = round($owed + $newDebt, 2);

        if ($after <= (float) $customer->credit_limit) {
            return;
        }

        throw ValidationException::withMessages([
            'credit_limit' => [sprintf(
                '%s has a khata limit of Rs %s and already owes Rs %s. This sale would take them to Rs %s.',
                $customer->name,
                number_format((float) $customer->credit_limit, 2),
                number_format($owed, 2),
                number_format($after, 2),
            )],
        ]);
    }

    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     * @return list<array{product: Product, quantity: float, unit_price: float, line_total: float}>
     */
    private function resolveLines(int $ownerId, array $items, bool $allowNegativeStock = false): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one item to the sale.'],
            ]);
        }

        // Merge duplicates first: scanning the same barcode three times must
        // check stock once against a quantity of 3, not three times against 1.
        // Rounding here rather than at the end keeps two 250 g scoops of daal
        // adding up to exactly 500 g on the ticket.
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $quantities[$id] = round(($quantities[$id] ?? 0.0) + (float) $item['quantity'], Unit::QUANTITY_DP);
        }

        $products = Product::query()
            ->where('user_id', $ownerId)
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

            // A line that rounds away to nothing — a scale that read zero, or a
            // quantity typed as text — would otherwise book a free item and,
            // if negative, hand stock back as though it were a refund.
            if ($quantity <= 0) {
                $errors['items'][] = "{$product->name}: enter a quantity greater than zero.";

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
                    $left = $product->quantityLabel((float) $product->stock_quantity);
                    $errors['items'][] = "{$product->name}: only {$left} left in stock.";

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
        float $delta,
        string $type,
        ?int $saleId = null,
        ?string $note = null,
    ): void {
        if (! $product->track_stock) {
            return;
        }

        // Rounded on every write, not just on display: a busy shop puts
        // thousands of decimal deltas through this line, and unrounded float
        // addition would leave the count creeping away from the shelf with
        // nothing to pull it back.
        $product->stock_quantity = round((float) $product->stock_quantity + $delta, Unit::QUANTITY_DP);
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
}
