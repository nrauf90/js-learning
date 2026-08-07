<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayBalance extends Model
{
    protected $fillable = [
        'user_id',
        'shop_id',
        'business_date',
        'opening_amount',
        'closing_amount',
        'expected_amount',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
        'opening_entry_id',
        'closing_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * How far the counted drawer was from what the till expected. Positive
     * means more cash than the system accounted for.
     */
    public function variance(): ?float
    {
        if ($this->closing_amount === null || $this->expected_amount === null) {
            return null;
        }

        return round((float) $this->closing_amount - (float) $this->expected_amount, 2);
    }
}
