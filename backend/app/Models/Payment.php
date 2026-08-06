<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'provider',
        'plan',
        'amount',
        'refunded_amount',
        'currency',
        'status',
        'txn_ref',
        'provider_reference',
        'external_transaction_id',
        'external_subscription_id',
        'invoice_number',
        'payload',
    ];

    /**
     * Raw gateway callback payload — internal only. Never expose it via the
     * admin API/UI; it can contain gateway-internal fields not meant for
     * the browser/network tab.
     *
     * @var list<string>
     */
    protected $hidden = [
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
