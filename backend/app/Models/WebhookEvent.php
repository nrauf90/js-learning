<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per provider webhook we have seen, keyed by the provider's own event
 * id. Paddle redelivers on any non-2xx response, and a redelivered
 * `subscription.updated` applied twice would re-extend a period — so every
 * handler runs behind this table's unique index.
 */
class WebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
        'result',
    ];

    /**
     * Raw provider payload — internal only, same reasoning as Payment::$hidden.
     *
     * @var list<string>
     */
    protected $hidden = [
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
