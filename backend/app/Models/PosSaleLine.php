<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSaleLine extends Model
{
    protected $fillable = [
        'pos_sale_id',
        'pos_product_id',
        'client_line_id',
        'product_name',
        'sku',
        'unit_price',
        'quantity',
        'quantity_refunded',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    public function refundLines(): HasMany
    {
        return $this->hasMany(PosRefundLine::class);
    }

    public function refundableQuantity(): int
    {
        return max(0, $this->quantity - $this->quantity_refunded);
    }
}
