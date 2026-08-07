<?php

namespace App\Models;

use App\Support\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'name',
        'unit_type',
        'base_unit',
        'price_unit',
        'quantity',
        'unit_cost',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:'.Unit::QUANTITY_DP,
            'unit_cost' => 'decimal:'.Unit::PRICE_DP,
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** "50 kg", not "50000" — the delivery note reads the way the van driver said it. */
    public function quantityLabel(): string
    {
        return Unit::formatQuantity((float) $this->quantity, $this->unit_type);
    }

    /** "Rs 180 / kg" — the cost as it was quoted, not the per-gram figure stored. */
    public function unitCostLabel(): string
    {
        return Unit::formatUnitPrice((float) $this->unit_cost, $this->price_unit);
    }
}
