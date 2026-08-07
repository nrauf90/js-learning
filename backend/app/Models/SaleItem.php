<?php

namespace App\Models;

use App\Support\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'name',
        'unit_type',
        'base_unit',
        'price_unit',
        'unit_price',
        'unit_cost',
        'quantity',
        'refunded_quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:'.Unit::PRICE_DP,
            'unit_cost' => 'decimal:'.Unit::PRICE_DP,
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:'.Unit::QUANTITY_DP,
            'refunded_quantity' => 'decimal:'.Unit::QUANTITY_DP,
        ];
    }

    /**
     * Rounded at the base unit's precision so a line refunded in pieces lands
     * on exactly zero rather than a hair above it, which is what tells a sale
     * it is fully refunded.
     */
    public function remainingQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->refunded_quantity, Unit::QUANTITY_DP));
    }

    public function quantityLabel(): string
    {
        return Unit::formatQuantity((float) $this->quantity, $this->unit_type);
    }

    public function unitPriceLabel(): string
    {
        return Unit::formatUnitPrice((float) $this->unit_price, $this->price_unit);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
