<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Pos\SalePaymentService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The udhaar khata: who owes the shop money, how old the debt is, and what
 * happens when they walk in and pay some of it back.
 *
 * Nothing here stores a balance. Every figure is derived from the sales and
 * their instalments, so the khata cannot drift away from the till — see
 * Customer::OUTSTANDING_SQL.
 */
class CustomerController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /**
     * How long a debt has been sitting, in the bands a shopkeeper actually
     * thinks in: this month, last month, the month before, and "this one has
     * been on the page long enough that I should go and knock on the door".
     * Each value is the inclusive upper age of the bucket in days; anything
     * older than the last one falls into `days_90_plus`.
     */
    private const AGING_BUCKETS = [
        'days_0_30' => 30,
        'days_31_60' => 60,
        'days_61_90' => 90,
    ];

    private const OLDEST_BUCKET = 'days_90_plus';

    public function __construct(private SalePaymentService $payments) {}

    /** The whole khata, debtors and settled customers alike. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        // dataOwnerId(), not id: staff work the shop owner's khata, so scoping
        // to the cashier would show them an empty notebook.
        $ownerId = $request->user()->dataOwnerId();

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $paginator = Customer::query()
            ->where('user_id', $ownerId)
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = '%'.$validated['search'].'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when(isset($validated['active']), fn ($query) => $query->where('is_active', $validated['active']))
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        $customers = collect($paginator->items());

        // One grouped aggregate for the page rather than a balance query per
        // row — a shop with 400 names on the khata would otherwise pay 400.
        $balances = $this->balancesFor($ownerId, $customers->pluck('id')->all());

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => $this->payload($c, $balances[$c->id] ?? null)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * "Who owes me" — the one screen the whole feature exists for.
     *
     * Newest debt first, because the customer who bought yesterday is the one
     * most likely to be back tomorrow; the aging buckets are what surface the
     * old debts the ordering pushes down.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $ownerId = $request->user()->dataOwnerId();
        $rows = $this->outstandingRows($ownerId);

        $customers = Customer::query()
            ->where('user_id', $ownerId)
            ->whereIn('id', array_keys($rows))
            ->get()
            ->keyBy('id');

        $list = collect($rows)
            // A page whose customer row has since been deleted still has its
            // sales, but there is nobody left to chase — it is not a debtor.
            ->filter(fn (array $row, int $id) => $customers->has($id))
            ->map(fn (array $row, int $id) => [
                ...$this->payload($customers->get($id), $row),
                'open_sales_count' => $row['open_sales_count'],
                'oldest_debt_at' => $this->isoDate($row['oldest_sold_at']),
                'latest_debt_at' => $this->isoDate($row['latest_sold_at']),
                'aging' => $row['aging'],
            ])
            ->sortByDesc('latest_debt_at')
            ->values();

        // Totalled before the search is applied: the tiles answer "what is the
        // shop owed", which does not become a smaller number because a name was
        // typed into the filter box.
        $totals = [
            'total_outstanding' => round($list->sum('balance'), 2),
            'debtors_count' => $list->count(),
            'aging' => $this->bucketKeys()
                ->mapWithKeys(fn (string $key) => [
                    $key => round($list->sum(fn (array $row) => $row['aging'][$key]), 2),
                ]),
        ];

        $matches = $this->matching($list, $validated['search'] ?? null);

        // Sliced here rather than in SQL: the balance, the age spread and the
        // ordering are all worked out in PHP from one grouped aggregate over
        // the open sales, so the rows have to exist before they can be ranked.
        // The database work is unchanged; what shrinks is the page the browser
        // has to render.
        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $lastPage = max(1, (int) ceil($matches->count() / $perPage));
        $page = min(max(1, (int) ($validated['page'] ?? 1)), $lastPage);

        return response()->json([
            'customers' => $matches->forPage($page, $perPage)->values(),
            ...$totals,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $matches->count(),
                'last_page' => $lastPage,
            ],
            // The buckets are measured from now, so the answer is only true as
            // of when it was asked.
            'as_of' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $validated = $request->validate($this->rules($request));

        $customer = Customer::create([
            'user_id' => $request->user()->dataOwnerId(),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'credit_limit' => $validated['credit_limit'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['customer' => $this->payload($customer)], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['customer' => $this->payload($customer)]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate($this->rules($request, $customer));

        $customer->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'credit_limit' => $validated['credit_limit'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? $customer->is_active,
        ]);

        return response()->json(['customer' => $this->payload($customer)]);
    }

    /**
     * The running statement for one page of the khata: every credit sale and
     * every rupee taken against it, in the order they happened, with the
     * balance after each. This is the notebook page, read top to bottom.
     *
     * The same page also answers the question the customer is actually standing
     * there asking — "I paid you two thousand last week, what is left?" — so the
     * payments are handed back a second time as their own history, newest
     * first, each with the balance it left behind. Both views are built from one
     * pass over the same entries, which is what stops the history and the
     * statement from ever quoting different balances for the same payment.
     */
    public function ledger(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $sales = $customer->sales()
            ->where('payment_method', 'credit')
            ->with('payments.recordedBy')
            ->orderBy('sold_at')
            ->orderBy('id')
            ->get();

        $entries = [];

        foreach ($sales as $sale) {
            $entries[] = $this->entry('sale', $sale->sold_at, $sale, [
                'description' => 'Credit sale',
                'charge' => round((float) $sale->total, 2),
            ]);

            foreach ($sale->payments as $payment) {
                $entries[] = $this->entry('payment', $payment->paid_at, $sale, [
                    'description' => $payment->note ?: 'Payment received',
                    'credit' => round((float) $payment->amount, 2),
                    'method' => $payment->method,
                    // The sale's own reference stays on the row — "which ticket
                    // did my money go on" is the question the customer asks —
                    // and the wallet transaction id sits beside it, not over it.
                    'payment_reference' => $payment->reference,
                    'payment_id' => $payment->id,
                    'received_by' => $payment->received_by_name,
                    // Carried for paymentHistory() below and dropped by
                    // publicEntry() before any of this leaves the controller.
                    'payment' => $payment,
                ]);
            }

            // Only the part of a refund that actually cancelled a debt belongs
            // on this page. If the goods were already paid for, the money went
            // back over the counter — a till movement, not a khata one — and
            // showing it here would make the page close on a balance the
            // customer does not owe.
            $credited = min(
                (float) $sale->refunded_amount,
                max(0.0, round((float) $sale->total - (float) $sale->paid_amount, 2))
            );

            if ($credited > 0) {
                $entries[] = $this->entry('refund', $sale->refunded_at ?? $sale->sold_at, $sale, [
                    'description' => 'Goods returned',
                    'credit' => round($credited, 2),
                ]);
            }
        }

        // A deposit taken at the till shares its timestamp with the sale, so
        // ties are broken by kind: the charge has to land before the money
        // against it or the page reads as though the customer paid in advance.
        usort($entries, function (array $a, array $b) {
            return [$a['at'], $a['rank'], $a['sale_id']] <=> [$b['at'], $b['rank'], $b['sale_id']];
        });

        $balance = 0.0;

        foreach ($entries as $index => $entry) {
            $balance = round($balance + $entry['charge'] - $entry['credit'], 2);
            $entries[$index]['balance'] = $balance;
        }

        return response()->json([
            'customer' => $this->payload($customer),
            'entries' => array_map(
                fn (array $entry) => [...$this->publicEntry($entry), 'balance' => $entry['balance']],
                $entries,
            ),
            'payments' => $this->paymentHistory($entries),
        ]);
    }

    /**
     * The customer walks in with Rs 2,000 and no idea which ticket it is for.
     * The money is spread across their unpaid sales oldest first, writing real
     * instalments so the drawer, the day book and each sale's status all move
     * exactly as they would if it had been taken sale by sale.
     */
    public function recordPayment(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('settle', $customer);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'max:9999999.99'],
            // 'credit' is missing on purpose: settling a debt with more credit
            // moves no money.
            'method' => ['required', Rule::in(Sale::SETTLEMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
            // Optional, because the owner settling their own khata is both the
            // login and the hand that took the notes. It earns its place when
            // they are not the same person.
            'received_by_name' => ['nullable', 'string', 'max:120'],
        ]);

        $allocations = $this->payments->settleOldestFirst($request->user(), $customer, [
            ...$validated,
            'note' => $validated['note'] ?? 'Khata payment',
        ]);

        $balance = $customer->outstandingBalance();
        $count = count($allocations);

        return response()->json([
            'message' => $balance <= 0
                ? 'Payment recorded. This khata is now clear.'
                : sprintf(
                    'Payment recorded against %d sale%s. Rs %s still owed.',
                    $count,
                    $count === 1 ? '' : 's',
                    number_format($balance, 2),
                ),
            'customer' => $this->payload($customer, ['balance' => $balance]),
            // Which tickets the money went on, so the shopkeeper can tell the
            // customer what their rupees just cleared.
            'allocations' => collect($allocations)->map(fn (array $row) => [
                'sale_id' => $row['sale']->id,
                'reference' => $row['sale']->reference,
                'amount' => round($row['amount'], 2),
                'payment_status' => $row['sale']->payment_status,
                'outstanding_amount' => $row['sale']->outstandingAmount(),
                'sold_at' => $row['sale']->sold_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Customer $customer = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Unique per shop because the till identifies a walk-in by their
            // number first (see SaleService::resolveCustomer). Two pages
            // sharing one number would make that lookup a coin toss.
            'phone' => [
                'nullable', 'string', 'max:32',
                Rule::unique('customers', 'phone')
                    ->where(fn ($q) => $q->where('user_id', $request->user()->dataOwnerId()))
                    ->ignore($customer?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            // Null clears the ceiling. Zero is a real value meaning "no more
            // credit for this one", so it is not filtered out as empty.
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Open balances for the given customers, keyed by customer id.
     *
     * @param  list<int>  $ids
     * @return array<int, array{balance: float}>
     */
    private function balancesFor(int $ownerId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->openSalesQuery($ownerId)
            ->whereIn('customer_id', $ids)
            ->selectRaw('customer_id, SUM('.Customer::OUTSTANDING_SQL.') as balance')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                (int) $row->customer_id => ['balance' => round((float) $row->balance, 2)],
            ])
            ->all();
    }

    /**
     * Balance, age spread and debt dates for every customer who owes something,
     * as one grouped aggregate.
     *
     * The buckets are compared against pre-computed cut-off timestamps rather
     * than a date-difference function, because MySQL and the SQLite used by the
     * test suite spell that function differently and this has to answer the
     * same on both.
     *
     * @return array<int, array{balance: float, open_sales_count: int, oldest_sold_at: mixed, latest_sold_at: mixed, aging: array<string, float>}>
     */
    private function outstandingRows(int $ownerId): array
    {
        $due = Customer::OUTSTANDING_SQL;

        $selects = [
            'customer_id',
            "SUM({$due}) as balance",
            'COUNT(*) as open_sales_count',
            'MIN(sold_at) as oldest_sold_at',
            'MAX(sold_at) as latest_sold_at',
        ];
        $bindings = [];

        // Cut at the start of the day so the bands follow calendar days: a debt
        // does not become a month old at the hour it was rung up.
        $newer = null;

        foreach (self::AGING_BUCKETS as $key => $days) {
            $floor = now()->subDays($days)->startOfDay()->toDateTimeString();

            $clause = 'sold_at >= ?';
            $bindings[] = $floor;

            if ($newer !== null) {
                $clause .= ' AND sold_at < ?';
                $bindings[] = $newer;
            }

            $selects[] = "SUM(CASE WHEN {$clause} THEN {$due} ELSE 0 END) as {$key}";
            $newer = $floor;
        }

        $selects[] = 'SUM(CASE WHEN sold_at < ? THEN '.$due.' ELSE 0 END) as '.self::OLDEST_BUCKET;
        $bindings[] = $newer;

        return $this->openSalesQuery($ownerId)
            ->groupBy('customer_id')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->get()
            ->mapWithKeys(fn (object $row) => [
                (int) $row->customer_id => [
                    'balance' => round((float) $row->balance, 2),
                    'open_sales_count' => (int) $row->open_sales_count,
                    'oldest_sold_at' => $row->oldest_sold_at,
                    'latest_sold_at' => $row->latest_sold_at,
                    'aging' => $this->bucketKeys()
                        ->mapWithKeys(fn (string $key) => [$key => round((float) $row->{$key}, 2)])
                        ->all(),
                ],
            ])
            ->all();
    }

    /**
     * Sales this shop is still owed for, linked to a khata page. The `> 0`
     * filter is what applies Sale::outstandingAmount()'s per-sale floor: an
     * over-refunded sale is money the shop owes back, and letting it net
     * against another sale's debt would understate what is collectable.
     */
    private function openSalesQuery(int $ownerId): QueryBuilder
    {
        return DB::table('sales')
            ->where('user_id', $ownerId)
            ->whereNotNull('customer_id')
            ->whereRaw(Customer::OUTSTANDING_SQL.' > 0');
    }

    /**
     * Name-or-number search over the debtor list, spelled the same way the
     * `index` query spells it in SQL, so the khata's two views answer the same
     * search the same way.
     *
     * @param  Collection<int, array<string, mixed>>  $list
     * @return Collection<int, array<string, mixed>>
     */
    private function matching(Collection $list, ?string $search): Collection
    {
        if (blank($search)) {
            return $list;
        }

        $needle = mb_strtolower($search);

        return $list->filter(fn (array $row) => str_contains(mb_strtolower((string) $row['name']), $needle)
            || str_contains(mb_strtolower((string) $row['phone']), $needle))
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function bucketKeys(): Collection
    {
        return collect(array_keys(self::AGING_BUCKETS))->push(self::OLDEST_BUCKET);
    }

    /**
     * One statement line, still carrying the sort keys the running balance
     * needs. publicEntry() strips them before it goes out.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function entry(string $type, ?Carbon $at, Sale $sale, array $extra): array
    {
        return [
            'type' => $type,
            'rank' => match ($type) {
                'sale' => 0,
                'payment' => 1,
                default => 2,
            },
            'at' => $at?->toDateTimeString() ?? '',
            'sale_id' => $sale->id,
            'reference' => $sale->reference,
            'charge' => 0.0,
            'credit' => 0.0,
            'method' => null,
            'payment_reference' => null,
            'payment_id' => null,
            'received_by' => null,
            'payment' => null,
            ...$extra,
        ];
    }

    /**
     * The payments on this page, newest first, each with what was left owing
     * once it landed.
     *
     * A lump sum is stored as one `sale_payments` row per ticket it cleared, but
     * the customer handed over one amount and that is the amount they will
     * argue about — so the rows of one payment are folded back into a single
     * line that names every ticket it went on. They are grouped by the stamp
     * the allocation shares (see SalePaymentService::settleOldestFirst) plus
     * everything else the shopkeeper would use to tell two payments apart; two
     * genuinely separate payments in the same second, by the same method, from
     * the same hands, are not something a counter can produce.
     *
     * The balance is taken from the statement rather than recomputed, so
     * "Rs 2,000 paid, Rs 1,500 still owed" reads the same on both.
     *
     * @param  list<array<string, mixed>>  $entries  chronological, balances applied
     * @return list<array<string, mixed>>
     */
    private function paymentHistory(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            if ($entry['type'] !== 'payment') {
                continue;
            }

            /** @var SalePayment $payment */
            $payment = $entry['payment'];

            $key = implode('|', [
                $entry['at'],
                (string) $payment->method,
                (string) $payment->recorded_by,
                (string) $payment->received_by_name,
                (string) $payment->reference,
                (string) $payment->note,
            ]);

            $groups[$key] ??= [
                'id' => $payment->id,
                'at' => $this->isoDate($entry['at']),
                'amount' => 0.0,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'note' => $payment->note,
                'received_by' => $payment->received_by_name,
                // The login beside the name: on a disputed payment the owner
                // needs both "who did you hand it to" and "whose till was it
                // typed into".
                'recorded_by' => $payment->recordedBy?->name,
                'balance_after' => 0.0,
                'allocations' => [],
            ];

            $groups[$key]['amount'] = round($groups[$key]['amount'] + $entry['credit'], 2);
            $groups[$key]['balance_after'] = $entry['balance'];
            $groups[$key]['allocations'][] = [
                'sale_id' => $entry['sale_id'],
                'reference' => $entry['reference'],
                'amount' => $entry['credit'],
            ];
        }

        return array_reverse(array_values($groups));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function publicEntry(array $entry): array
    {
        return [
            'type' => $entry['type'],
            'at' => $this->isoDate($entry['at']),
            'sale_id' => $entry['sale_id'],
            'reference' => $entry['reference'],
            'description' => $entry['description'],
            'charge' => $entry['charge'],
            'credit' => $entry['credit'],
            'method' => $entry['method'],
            'payment_reference' => $entry['payment_reference'],
            'payment_id' => $entry['payment_id'],
            'received_by' => $entry['received_by'],
        ];
    }

    /** Aggregates come back as driver-shaped strings, so they are re-read here. */
    private function isoDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * @param  array{balance: float}|null  $balance  pre-computed, to keep list
     *                                               endpoints to one query
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, ?array $balance = null): array
    {
        $due = $balance['balance'] ?? $customer->outstandingBalance();

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'credit_limit' => $customer->credit_limit === null ? null : (float) $customer->credit_limit,
            // Null means no ceiling was ever set, which reads very differently
            // from a ceiling with nothing left under it.
            'credit_available' => $customer->creditAvailable($due),
            'notes' => $customer->notes,
            'is_active' => (bool) $customer->is_active,
            'balance' => $due,
        ];
    }
}
