<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRefundLine extends Model
{
    protected $fillable = [
        'pos_refund_id',
        'pos_sale_line_id',
        'quantity_refunded',
        'amount_refunded',
    ];

    protected function casts(): array
    {
        return [
            'amount_refunded' => 'decimal:2',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PosRefund::class, 'pos_refund_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(PosSaleLine::class, 'pos_sale_line_id');
    }
}
