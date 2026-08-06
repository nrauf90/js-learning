<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    public const PAYMENT_METHODS = ['cash', 'card', 'other'];

    protected $fillable = [
        'user_id',
        'reference',
        'client_uuid',
        'is_offline',
        'cash_entry_id',
        'subtotal',
        'discount_amount',
        'total',
        'refunded_amount',
        'payment_method',
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
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
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

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
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
}
