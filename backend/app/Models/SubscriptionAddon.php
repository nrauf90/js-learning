<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionAddon extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'external_id',
        'addon_key',
        'status',
        'ends_at',
        'cancel_at_period_end',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, Subscription::ACTIVE_STATUSES, true)
            && $this->ends_at
            && $this->ends_at->isFuture();
    }
}
