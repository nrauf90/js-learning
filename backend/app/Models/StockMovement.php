<?php

namespace App\Models;

use App\Support\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const REASON_DAMAGED = 'damaged';

    public const REASON_EXPIRED = 'expired';

    public const REASON_SPOILED = 'spoiled';

    public const REASON_THEFT = 'theft';

    public const REASON_SAMPLE = 'sample';

    public const REASON_COUNT_CORRECTION = 'count_correction';

    /**
     * Why stock came off the shelf without being sold.
     *
     * A fixed list, not free text: the whole point of asking is to be able to
     * add the year up by cause, and "kharab ho gaya", "spoiled" and "bad" are
     * three spellings of one number.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_DAMAGED,
        self::REASON_EXPIRED,
        self::REASON_SPOILED,
        self::REASON_THEFT,
        self::REASON_SAMPLE,
        self::REASON_COUNT_CORRECTION,
    ];

    /**
     * The subset that is genuinely money gone. A recount is not a loss — the
     * goods were never there, so the shop is only correcting its own book, and
     * totalling it as wastage would charge the same rupees twice.
     *
     * @var list<string>
     */
    public const WASTAGE_REASONS = [
        self::REASON_DAMAGED,
        self::REASON_EXPIRED,
        self::REASON_SPOILED,
        self::REASON_THEFT,
        self::REASON_SAMPLE,
    ];

    protected $fillable = [
        'user_id',
        'product_id',
        'sale_id',
        'type',
        'reason',
        'quantity_delta',
        'balance_after',
        'cost_value',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:'.Unit::QUANTITY_DP,
            'balance_after' => 'decimal:'.Unit::QUANTITY_DP,
            'cost_value' => 'decimal:2',
        ];
    }

    /**
     * What a movement of `$delta` base units is worth at `$baseCost` per base
     * unit — Rs 90 of daal, not "0.5".
     *
     * Unsigned: the direction already lives in `quantity_delta`, and a wastage
     * report reads "Rs 4,300 thrown away this month", never minus that.
     * Null cost means the shop never recorded what it paid, and inventing a
     * zero there would report a free loss rather than an unknown one.
     */
    public static function valueAtCost(float $delta, ?float $baseCost): ?float
    {
        return $baseCost === null ? null : round(abs($delta) * $baseCost, 2);
    }

    public function isWastage(): bool
    {
        return in_array($this->reason, self::WASTAGE_REASONS, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
