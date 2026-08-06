<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'user_id',
        'product_category_id',
        'name',
        'sku',
        'barcode',
        'price',
        'cost',
        'track_stock',
        'stock_quantity',
        'low_stock_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Untracked products are always sellable — there is no count to run down.
     */
    public function hasStockFor(int $quantity): bool
    {
        return ! $this->track_stock || $this->stock_quantity >= $quantity;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && $this->low_stock_threshold > 0
            && $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        // Barcode is matched exactly and first: a scanner submits the full code
        // and the cashier expects the one item, not a fuzzy list.
        $query->where(function (Builder $q) use ($term) {
            $q->where('barcode', $term)
                ->orWhere('sku', $term)
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }
}
