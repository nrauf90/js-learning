<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /**
     * One sale's unpaid balance, in SQL. Mirrors Sale::outstandingAmount()
     * exactly — including the way a refund shrinks the debt — so the khata
     * screen and the sale screen can never disagree about what is owed. It is
     * an expression rather than a stored column for the same reason
     * `sales.paid_amount` is reconciled from `sale_payments`: a cached balance
     * is a balance that eventually drifts.
     *
     * Rows are always filtered to `> 0` before it is summed, which is what
     * applies the per-sale floor that outstandingAmount()'s max(0, …) applies.
     */
    public const OUTSTANDING_SQL = 'sales.total - sales.refunded_amount - sales.paid_amount';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'address',
        'credit_limit',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Sales still owed for, oldest first — which is both how a shopkeeper reads
     * the page and the order a lump-sum payment pays them off in.
     */
    public function openSales(): HasMany
    {
        return $this->sales()
            ->whereRaw(self::OUTSTANDING_SQL.' > 0')
            ->orderBy('sold_at')
            ->orderBy('id');
    }

    /** Everything this customer still owes the shop, across all their sales. */
    public function outstandingBalance(): float
    {
        $due = $this->sales()
            ->whereRaw(self::OUTSTANDING_SQL.' > 0')
            ->selectRaw('SUM('.self::OUTSTANDING_SQL.') as due')
            ->value('due');

        return round((float) $due, 2);
    }

    /**
     * How much further this customer may go on credit. Null means the shop
     * never set a ceiling, not that the ceiling has been reached — the two read
     * very differently on the screen and only one of them refuses a sale.
     */
    public function creditAvailable(?float $balance = null): ?float
    {
        if ($this->credit_limit === null) {
            return null;
        }

        return round(max(0, (float) $this->credit_limit - ($balance ?? $this->outstandingBalance())), 2);
    }
}
