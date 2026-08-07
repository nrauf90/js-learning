<?php

namespace App\Services\Pos;

use App\Models\CashEntry;
use App\Models\DayBalance;
use App\Models\ExpenseCategory;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The till's day book: the float that goes into the drawer in the morning and
 * the cash counted out of it at night.
 *
 * Individual sales no longer post into the cash ledger, so these two entries
 * are the shop's whole day as far as cash flow is concerned. They are booked as
 * an expense (money leaving the owner to sit in the drawer) and an income (the
 * drawer emptied back out), which nets to exactly the day's cash gain without
 * counting the float twice.
 */
class DayBookService
{
    public const FLOAT_CATEGORY_SLUG = 'till-float';

    public const CLOSE_CATEGORY_SLUG = 'till-close';

    /**
     * The shop's local date. Deliberately derived from the server clock and
     * never from request input — a spoofable business date would let a till
     * post today's takings into a day that is already closed and reconciled.
     */
    public function businessDate(): string
    {
        return now()->toDateString();
    }

    public function findForDate(User $user, string $date): ?DayBalance
    {
        return DayBalance::query()
            ->where('user_id', $user->dataOwnerId())
            ->whereDate('business_date', $date)
            ->first();
    }

    /**
     * Record the opening float.
     *
     * Idempotent by necessity: the till prompts for a float whenever it loads
     * without one, and two terminals opening the shop at the same moment must
     * end up sharing one day book rather than racing to create two floats.
     */
    public function open(User $user, float $openingAmount): DayBalance
    {
        $date = $this->businessDate();

        if ($existing = $this->findForDate($user, $date)) {
            return $this->assertNotClosed($existing);
        }

        try {
            return DB::transaction(function () use ($user, $date, $openingAmount) {
                $amount = round($openingAmount, 2);

                $entry = $this->postCashEntry(
                    $user,
                    $date,
                    self::FLOAT_CATEGORY_SLUG,
                    'expense',
                    'Till Float',
                    $amount,
                    'Opening float '.$date,
                );

                return DayBalance::create([
                    'user_id' => $user->dataOwnerId(),
                    'shop_id' => $this->shopId($user),
                    'business_date' => $date,
                    'opening_amount' => $amount,
                    'opened_at' => now(),
                    'opened_by' => $user->id,
                    'opening_entry_id' => $entry?->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The unique index on (user_id, business_date) is the real
            // guarantee; the lookup above only saves the common case. Whoever
            // lost the race joins the day book the winner just created.
            $existing = $this->findForDate($user, $date);

            if (! $existing) {
                throw ValidationException::withMessages([
                    'opening_amount' => ['Could not open the day book. Please try again.'],
                ]);
            }

            return $this->assertNotClosed($existing);
        }
    }

    /**
     * Record the counted drawer and freeze what the system expected to find in
     * it. `expected_amount` is stored rather than derived on read so a refund
     * or a late sync tomorrow cannot quietly rewrite yesterday's variance.
     */
    public function close(User $user, float $closingAmount): DayBalance
    {
        $date = $this->businessDate();

        return DB::transaction(function () use ($user, $date, $closingAmount) {
            // Locked for the duration: two cashiers hitting "close" together
            // would otherwise both see an open day and post two closing entries.
            $day = DayBalance::query()
                ->where('user_id', $user->dataOwnerId())
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->first();

            if (! $day) {
                throw ValidationException::withMessages([
                    'closing_amount' => ['The till was never opened today. Record an opening balance first.'],
                ]);
            }

            if (! $day->isOpen()) {
                throw ValidationException::withMessages([
                    'closing_amount' => ['Today\'s day book has already been closed.'],
                ]);
            }

            $amount = round($closingAmount, 2);
            $cash = $this->cashPosition($user, $date);

            $entry = $this->postCashEntry(
                $user,
                $date,
                self::CLOSE_CATEGORY_SLUG,
                'income',
                'Till Close',
                $amount,
                'Closing cash '.$date,
            );

            $day->forceFill([
                'closing_amount' => $amount,
                'expected_amount' => round((float) $day->opening_amount + $cash['net'], 2),
                'closed_at' => now(),
                'closed_by' => $user->id,
                'closing_entry_id' => $entry?->id,
            ])->save();

            return $day;
        });
    }

    /**
     * What the drawer should hold right now: the float plus the cash that has
     * actually passed over the counter today.
     */
    public function expectedCash(User $user, string $date, float $openingAmount): float
    {
        return round($openingAmount + $this->cashPosition($user, $date)['net'], 2);
    }

    /**
     * The day's money, split into what reached the drawer and what did not.
     *
     * Only cash moves the drawer. Card, wallet and bank-transfer takings settle
     * elsewhere, and a sale handed over on credit puts nothing in the till at
     * all until the customer comes back to pay.
     *
     * @return array{in: float, refunds: float, net: float, sales_total: float, sales_count: int}
     */
    public function cashPosition(User $user, string $date): array
    {
        $ownerId = $user->dataOwnerId();

        $sales = Sale::query()
            ->with('payments')
            ->where('user_id', $ownerId)
            ->whereDate('sold_at', $date)
            ->get();

        $tillCash = $sales->sum(function (Sale $sale) {
            if ($sale->payment_method !== 'cash') {
                return 0.0;
            }

            // Instalments are credited to the day they were taken, below, so
            // anything itemised there is stripped out of the till figure to
            // stop a same-day down payment being counted twice.
            return max(0.0, round($this->collectedAmount($sale) - $this->cashInstalments($sale), 2));
        });

        // Settlements against credit sales — including sales from earlier days,
        // because the cash lands in the drawer on the day it is handed over.
        $instalmentCash = (float) SalePayment::query()
            ->whereHas('sale', fn ($query) => $query->where('user_id', $ownerId))
            ->where('method', 'cash')
            ->whereDate('paid_at', $date)
            ->sum('amount');

        // Refunds go back the way the money came in, so only a cash sale takes
        // notes back out of the drawer. Attributed to the day of the refund
        // rather than the day of the sale for the same reason.
        $refunds = (float) Sale::query()
            ->where('user_id', $ownerId)
            ->where('payment_method', 'cash')
            ->whereDate('refunded_at', $date)
            ->sum('refunded_amount');

        $in = round((float) $tillCash + $instalmentCash, 2);
        $out = round($refunds, 2);

        return [
            'in' => $in,
            'refunds' => $out,
            'net' => round($in - $out, 2),
            // Takings across every payment method, net of anything handed back
            // — the headline number the cashier sees next to the drawer count.
            'sales_total' => round((float) $sales->sum(fn (Sale $sale) => $sale->refundableTotal()), 2),
            'sales_count' => $sales->where('status', '!=', 'refunded')->count(),
        ];
    }

    /**
     * How much of a sale has actually been collected.
     *
     * `paid_amount` is authoritative once sales can go out on credit, but rows
     * settled outright at the till never itemise it, so a paid sale with
     * nothing recorded against it collected its total.
     */
    private function collectedAmount(Sale $sale): float
    {
        $paid = round((float) $sale->paid_amount, 2);
        $total = round((float) $sale->total, 2);

        if ($sale->payment_status === 'paid' && $paid <= 0) {
            return $total;
        }

        return min($paid, $total);
    }

    private function cashInstalments(Sale $sale): float
    {
        return round((float) $sale->payments->where('method', 'cash')->sum('amount'), 2);
    }

    /**
     * The day book belongs to the shop, but the drawer is a person's
     * responsibility — so the row is filed under the owner and stamped with
     * whichever account actually turned the key.
     */
    private function shopId(User $user): ?int
    {
        return $user->shop_id ?: $user->ownedShop?->id;
    }

    private function postCashEntry(
        User $user,
        string $date,
        string $slug,
        string $type,
        string $name,
        float $amount,
        string $note,
    ): ?CashEntry {
        if ($amount <= 0) {
            // An empty drawer is a real opening, but `cash_entries.amount` is
            // positive-only everywhere else in the app — there is simply
            // nothing to post.
            return null;
        }

        return CashEntry::create([
            'user_id' => $user->dataOwnerId(),
            'category_id' => $this->category($slug, $type, $name)->id,
            'type' => $type,
            'amount' => $amount,
            'entry_date' => $date,
            'note' => $note,
        ]);
    }

    /**
     * Created on first use rather than seeded: a shop that never opens a day
     * book has no business seeing these two in its category picker.
     */
    private function category(string $slug, string $kind, string $name): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'kind' => $kind,
                'icon' => null,
                'is_system' => true,
            ],
        );
    }

    private function assertNotClosed(DayBalance $day): DayBalance
    {
        if (! $day->isOpen()) {
            throw ValidationException::withMessages([
                'opening_amount' => ['Today\'s day book has already been closed.'],
            ]);
        }

        return $day;
    }
}
