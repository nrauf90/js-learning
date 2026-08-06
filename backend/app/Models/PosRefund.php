<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRefund extends Model
{
    protected $fillable = [
        'pos_sale_id',
        'user_id',
        'client_refund_id',
        'idempotency_key',
        'total_refunded',
        'reason',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'total_refunded' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosRefundLine::class);
    }
}
