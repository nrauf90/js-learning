<?php

namespace App\Services\Pos;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePaymentService
{
    /**
     * Collect an instalment against a sale that went out on credit.
     *
     * The sale row is locked for the whole transaction. Two cashiers settling
     * the same debt at two tills is the obvious way for a shop to over-collect:
     * without the lock both would read the same outstanding balance, both would
     * pass the over-payment check, and the customer would be charged twice for
     * the same rupees.
     *
     * @param  array{amount: float|string, method: string, reference?: string|null, note?: string|null, received_by_name?: string|null, paid_at?: CarbonInterface|null}  $data
     */
    public function settle(User $actor, Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($actor, $sale, $data) {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            // Rounded before it is compared to the outstanding balance, which is
            // itself rounded — otherwise a 0.005 of float drift turns into a
            // balance the customer can never quite pay off.
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter an amount greater than zero.'],
                ]);
            }

            $outstanding = $locked->outstandingAmount();

            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['There is nothing left to settle on this sale.'],
                ]);
            }

            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => ['Only '.number_format($outstanding, 2).' is outstanding on this sale.'],
                ]);
            }

            $locked->forceFill(['paid_amount' => round((float) $locked->paid_amount + $amount, 2)]);
            $locked->forceFill(['payment_status' => $locked->resolvePaymentStatus()])->save();

            $locked->payments()->create([
                // Who took the money, not whose shop it is — staff settle debts
                // against their shop owner's sale.
                'recorded_by' => $actor->id,
                // Blank is stored as null rather than '' so "nobody wrote a
                // name down" and "the name is empty" cannot read differently on
                // the khata page.
                'received_by_name' => $this->receivedBy($data),
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                // Never taken from request input — see settleOldestFirst(),
                // which is the only caller that supplies one.
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            return $locked->fresh(['items', 'payments']);
        });
    }

    /**
     * Take a lump sum against a customer's khata and spread it across their
     * unpaid sales, oldest first.
     *
     * Oldest-first is simply how the notebook is worked: the customer puts two
     * thousand rupees on the counter, they do not nominate which of last
     * month's four tickets it settles. Clearing the oldest debt first is also
     * what keeps the aging report honest.
     *
     * Every allocation goes through settle() above rather than writing
     * `sale_payments` directly, so the instalment rows, the payment status and
     * — crucially — the day book all behave exactly as they do at the till.
     *
     * `received_by_name` rides along on every allocation for the same reason:
     * one handful of notes taken by one person has to be traceable to them on
     * each ticket it was split across, not only on the first.
     *
     * @param  array{amount: float|string, method: string, reference?: string|null, note?: string|null, received_by_name?: string|null}  $data
     * @return list<array{sale: Sale, amount: float}>
     */
    public function settleOldestFirst(User $actor, Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($actor, $customer, $data) {
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter an amount greater than zero.'],
                ]);
            }

            // The whole set is locked before anything is totalled. A second
            // till taking money from the same customer at the same moment would
            // otherwise allocate against balances that are about to move under
            // it, and between them the shop would over-collect.
            $sales = $customer->openSales()->lockForUpdate()->get();
            $owed = round($sales->sum(fn (Sale $sale) => $sale->outstandingAmount()), 2);

            if ($owed <= 0) {
                throw ValidationException::withMessages([
                    'amount' => [$customer->name.' has nothing outstanding on their khata.'],
                ]);
            }

            // Refused rather than left as a credit in the customer's favour:
            // this app has nowhere to hold money the shop owes back, and
            // silently absorbing it would lose the difference.
            if ($amount > $owed) {
                throw ValidationException::withMessages([
                    'amount' => ['Only Rs '.number_format($owed, 2).' is owed on this khata.'],
                ]);
            }

            $remaining = $amount;
            $allocations = [];

            // One stamp for the whole lump sum, taken once rather than per
            // allocation. The rows are what the payment history groups on, and
            // a loop that straddled a second boundary would split one handful
            // of notes into two payments on the customer's page.
            $paidAt = now();

            foreach ($sales as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $sale->outstandingAmount());

                $allocations[] = [
                    'sale' => $this->settle($actor, $sale, array_merge($data, [
                        'amount' => $take,
                        'paid_at' => $paidAt,
                    ])),
                    'amount' => $take,
                ];

                $remaining = round($remaining - $take, 2);
            }

            return $allocations;
        });
    }

    /**
     * Take back a payment that should never have been written down — the
     * mis-typed instalment the khata otherwise carries forever.
     *
     * The rows are voided, not deleted: `reversed_at` is stamped on them and
     * the ledger keeps showing the line, marked, so a disputed khata can still
     * explain itself. Everywhere that sums this table filters reversed rows
     * out, which is what actually puts the money back on the customer's page.
     *
     * A lump sum is one row per ticket it cleared, all sharing the stamp
     * CustomerController::paymentHistory() folds them back together on — so
     * reversal voids the whole group, keyed exactly the way the history groups
     * it. Voiding only the row the caller happened to hold would un-pay one
     * ticket of a payment the customer remembers making once.
     *
     * @return array{payments: Collection<int, SalePayment>, sales: Collection<int, Sale>, amount: float}
     */
    public function reverse(User $actor, Customer $customer, SalePayment $payment): array
    {
        return DB::transaction(function () use ($actor, $customer, $payment) {
            $locked = SalePayment::query()->lockForUpdate()->findOrFail($payment->id);

            // The same fold the payment history draws: one stamp plus
            // everything that tells one handful of notes from another. The
            // ledger shows this group as a single line, so it is reversed as a
            // single line.
            $siblings = SalePayment::query()
                ->whereIn('sale_id', $customer->sales()->select('id'))
                ->where('paid_at', $locked->paid_at->toDateTimeString())
                ->where(function ($query) use ($locked) {
                    foreach (['method', 'recorded_by', 'received_by_name', 'reference', 'note'] as $field) {
                        $value = $locked->getAttribute($field);
                        $value === null ? $query->whereNull($field) : $query->where($field, $value);
                    }
                })
                ->lockForUpdate()
                ->get();

            if (! $siblings->contains('id', $locked->id)) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment is not on this khata.'],
                ]);
            }

            if ($siblings->contains(fn (SalePayment $row) => $row->reversed_at !== null)) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment has already been reversed.'],
                ]);
            }

            // Locked before the balances move, same as settleOldestFirst:
            // a till settling this khata at the same moment would otherwise
            // collect against a balance that is about to grow again.
            $sales = Sale::query()
                ->whereIn('id', $siblings->pluck('sale_id')->unique()->all())
                ->lockForUpdate()
                ->get();

            // paid_amount is re-derived, never decremented. Every rupee of a
            // credit sale's paid figure is itemised in sale_payments — the
            // till deposit included — but a sale part-paid some other way can
            // hold money no row explains; that unitemised floor survives the
            // reversal untouched.
            $floors = $sales->mapWithKeys(fn (Sale $sale) => [
                $sale->id => max(0.0, round(
                    (float) $sale->paid_amount - (float) $sale->payments()->sum('amount'),
                    2
                )),
            ]);

            $stamp = now();
            $ids = $siblings->pluck('id')->all();

            SalePayment::query()->whereIn('id', $ids)->update([
                'reversed_at' => $stamp,
                'reversed_by_user_id' => $actor->id,
            ]);

            $siblings->each(fn (SalePayment $row) => $row->forceFill([
                'reversed_at' => $stamp,
                'reversed_by_user_id' => $actor->id,
            ]));

            foreach ($sales as $sale) {
                $paid = round(
                    $floors[$sale->id]
                    + (float) $sale->payments()->whereNull('reversed_at')->sum('amount'),
                    2
                );

                $sale->forceFill(['paid_amount' => $paid]);
                // The same three-way call settle() makes: outstanding against
                // the new paid figure decides paid / partial / pending.
                $sale->forceFill(['payment_status' => $sale->resolvePaymentStatus()])->save();
            }

            return [
                'payments' => $siblings,
                'sales' => $sales,
                'amount' => round((float) $siblings->sum('amount'), 2),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function receivedBy(array $data): ?string
    {
        $name = trim((string) ($data['received_by_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
