<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /**
     * `credit` is the sale going out unpaid; the wallet and bank options are
     * settled-now methods that carry a transaction reference.
     */
    public const PAYMENT_METHODS = [
        'cash', 'card', 'easypaisa', 'jazzcash', 'bank_transfer', 'credit', 'other',
    ];

    /** Methods that move money now, so they may settle a pending balance. */
    public const SETTLEMENT_METHODS = [
        'cash', 'card', 'easypaisa', 'jazzcash', 'bank_transfer', 'other',
    ];

    public const PAYMENT_STATUSES = ['paid', 'partial', 'pending'];

    protected $fillable = [
        'user_id',
        'customer_id',
        'reference',
        'client_uuid',
        'is_offline',
        'cash_entry_id',
        'subtotal',
        'discount_amount',
        'rounding_adjustment',
        'total',
        'refunded_amount',
        'payment_method',
        'payment_reference',
        'payment_status',
        'paid_amount',
        'customer_name',
        'customer_phone',
        'amount_tendered',
        'change_due',
        'status',
        'refunded_at',
        'sold_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'rounding_adjustment' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
            'is_offline' => 'boolean',
            'sold_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * The khata page this sale is on. Null for anything settled at the counter,
     * and null again if the page is later deleted — `customer_name` and
     * `customer_phone` remain as the snapshot of who took the goods, the same
     * way `sale_items` keeps the name and price the item sold at.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Instalments collected against this sale, oldest first. */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('paid_at');
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    /** What is still refundable, net of anything already returned. */
    public function refundableTotal(): float
    {
        return round((float) $this->total - (float) $this->refunded_amount, 2);
    }

    /**
     * Money still owed. Derived rather than stored so it cannot drift from the
     * instalments in `sale_payments`; refunds reduce what is collectable, so a
     * refunded credit sale stops chasing the customer for the returned goods.
     */
    public function outstandingAmount(): float
    {
        return max(0, round(
            (float) $this->total - (float) $this->refunded_amount - (float) $this->paid_amount,
            2
        ));
    }

    /**
     * Recomputes `payment_status` from what has actually been collected. The
     * single place the three statuses are decided, so the till, the settle
     * endpoint and refunds cannot disagree about what "partial" means.
     */
    public function resolvePaymentStatus(): string
    {
        $paid = (float) $this->paid_amount;

        if ($this->outstandingAmount() <= 0) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'pending';
    }

    public function isSettled(): bool
    {
        return $this->outstandingAmount() <= 0;
    }
}
