<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /**
     * Statuses that still grant product access.
     *
     * `past_due` is included on purpose: Paddle retries a failed renewal over
     * several days, and cutting access on the first failure punishes people for
     * an expired card. Access still ends when `ends_at` passes.
     *
     * `canceled` is excluded — Paddle keeps a cancelling subscription `active`
     * with a `scheduled_change` until the period actually ends, so by the time
     * the status flips the customer is genuinely done.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    protected $fillable = [
        'user_id',
        'provider',
        'external_id',
        'external_price_id',
        'plan',
        'status',
        'starts_at',
        'ends_at',
        'renews_at',
        'trial_ends_at',
        'canceled_at',
        'cancel_at_period_end',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'renews_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true)
            && $this->ends_at
            && $this->ends_at->isFuture();
    }
}
