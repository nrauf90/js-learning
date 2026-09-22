<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    protected $fillable = [
        'sale_id',
        'recorded_by',
        // The person who physically took the money, which is not always the
        // login that entered it — see the 2026_08_08_100005 migration.
        'received_by_name',
        'amount',
        'method',
        'reference',
        'note',
        'paid_at',
    ];

    // `reversed_at` / `reversed_by_user_id` are deliberately not fillable: they
    // are only ever stamped by SalePaymentService::reverse(), never taken from
    // a request payload.

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** Voided instalments still stand on the page, marked rather than gone. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
