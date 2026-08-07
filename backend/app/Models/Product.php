<?php

namespace App\Models;

use App\Support\Unit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = [
        'user_id',
        'product_category_id',
        'name',
        'unit_type',
        'base_unit',
        'price_unit',
        'sku',
        'barcode',
        'image_path',
        'price',
        'cost',
        'track_stock',
        'stock_quantity',
        'low_stock_threshold',
        'pack_size',
        'pack_label',
        'expiry_date',
        'track_expiry',
        'is_active',
    ];

    /**
     * How far ahead a date has to be before the shopkeeper stops caring.
     *
     * One number for both the badge in the catalogue and the default window on
     * the expiring filter: a shelf that warns at 30 days and lists at 7 teaches
     * the shopkeeper that the badge and the list disagree.
     */
    public const EXPIRY_SOON_DAYS = 30;

    /**
     * Mirrors the column defaults from the migration.
     *
     * Without these a freshly created model carries no value for anything the
     * request omitted — the database fills the column, but the in-memory
     * instance keeps returning null. That silently skipped the opening-stock
     * movement in ProductController::store(), because `$product->track_stock`
     * was null rather than true on a product created without the field.
     */
    protected $attributes = [
        'unit_type' => Unit::TYPE_EACH,
        'base_unit' => 'pc',
        'price_unit' => 'pc',
        'track_stock' => true,
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
        'pack_size' => 1,
        'track_expiry' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            // Tied to the same constants the migration sizes the columns with,
            // so the cast cannot quietly round tighter than the schema stores.
            'price' => 'decimal:'.Unit::PRICE_DP,
            'cost' => 'decimal:'.Unit::PRICE_DP,
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'stock_quantity' => 'decimal:'.Unit::QUANTITY_DP,
            'low_stock_threshold' => 'decimal:'.Unit::QUANTITY_DP,
            'pack_size' => 'decimal:'.Unit::QUANTITY_DP,
            'expiry_date' => 'date',
            'track_expiry' => 'boolean',
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
     *
     * The half-milligram of slack matters now that both sides are decimals:
     * weighing out the last 1.5 kg of a 1.5 kg sack must not be refused because
     * the two floats disagree in the fifteenth place.
     */
    public function hasStockFor(float $quantity): bool
    {
        return ! $this->track_stock || (float) $this->stock_quantity + 0.0005 >= $quantity;
    }

    /** Absolute URL for the till tile, or null when the product has no image. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && (float) $this->low_stock_threshold > 0
            && (float) $this->stock_quantity <= (float) $this->low_stock_threshold;
    }

    /**
     * How many days until the date on the packet — negative once it has passed.
     *
     * Null when the shop is not watching this product, so callers cannot read a
     * stale date left on a row whose owner turned the warnings off.
     */
    public function daysToExpiry(): ?int
    {
        if (! $this->track_expiry || $this->expiry_date === null) {
            return null;
        }

        return (int) round(Carbon::today()->diffInDays($this->expiry_date, false));
    }

    /**
     * Expired *yesterday*, not today: a packet dated the 10th is good all day on
     * the 10th, and marking it dead at midnight would have the shop throwing
     * away a day of saleable milk every time.
     */
    public function isExpired(): bool
    {
        $days = $this->daysToExpiry();

        return $days !== null && $days < 0;
    }

    public function isExpiringSoon(int $days = self::EXPIRY_SOON_DAYS): bool
    {
        $left = $this->daysToExpiry();

        return $left !== null && $left >= 0 && $left <= $days;
    }

    /**
     * "1 Peti = 24 pc" — the buying pack spelled out.
     *
     * Null for a pack of one, which is every product that is simply bought the
     * way it is sold; printing "1 Pack = 1 pc" on those would put a line of
     * noise under most of the catalogue.
     */
    public function packLabelText(): ?string
    {
        $size = (float) $this->pack_size;

        if ($size <= 1) {
            return null;
        }

        $label = filled($this->pack_label) ? $this->pack_label : 'Pack';

        // formatQuantity() deliberately leaves counted goods bare ("24"), which
        // reads fine as a stock line but not as an equation — so pieces get
        // their unit back here and only here.
        $quantity = Unit::formatQuantity($size, $this->unit_type);

        if ($this->unit_type === Unit::TYPE_EACH) {
            $quantity .= ' '.Unit::short($this->base_unit);
        }

        return "1 {$label} = {$quantity}";
    }

    /**
     * Everything with a date the shop is watching that falls on or before
     * `$days` from today — which sweeps up what has already gone off, because
     * a bottle that expired last week is the most urgent thing on the shelf.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeExpiring(Builder $query, int $days = self::EXPIRY_SOON_DAYS): void
    {
        $query->where('track_expiry', true)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', Carbon::today()->addDays($days));
    }

    /** Weighed or poured, as opposed to counted out one at a time. */
    public function isMeasured(): bool
    {
        return $this->unit_type !== Unit::TYPE_EACH;
    }

    /** The price the way the shopkeeper quotes it: Rs 250/kg, not Rs 0.25/g. */
    public function displayPrice(): float
    {
        return Unit::priceFromBase((float) $this->price, $this->price_unit);
    }

    public function displayCost(): ?float
    {
        return $this->cost === null
            ? null
            : Unit::priceFromBase((float) $this->cost, $this->price_unit);
    }

    public function quantityLabel(float $base): string
    {
        return Unit::formatQuantity($base, $this->unit_type);
    }

    public function unitPriceLabel(): string
    {
        return Unit::formatUnitPrice((float) $this->price, $this->price_unit);
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
