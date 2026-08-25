<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * The transfer screenshot, or a photo of the slip the customer was
     * given.
     *
     * One khata payment is spread across several sales oldest-first, so it
     * writes several of these rows. The picture is filed against the first
     * of them and CustomerController::paymentHistory() gathers attachments
     * back across the whole group, which is the same grouping the ledger
     * already uses to show one handful of notes as one line.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->oldest('id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
