<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSale extends Model
{
    protected $fillable = [
        'user_id',
        'client_sale_id',
        'idempotency_key',
        'subtotal',
        'tax',
        'total',
        'payment_method',
        'status',
        'sold_at',
        'sync_source',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleLine::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PosRefund::class);
    }
}
