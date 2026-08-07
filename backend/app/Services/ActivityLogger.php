<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the "who changed what" trail for the catalogue.
 *
 * `changes` is stored as a map keyed by field name. A diff entry is
 * `{"price": {"from": "120.00", "to": "130.00"}}`; a create/delete snapshot and
 * the context of a stock adjustment are plain values, `{"sku": "RICE-001"}`.
 * The report renderer handles both shapes, which is what lets one column answer
 * "what did it look like when it was deleted" as well as "what moved".
 */
class ActivityLogger
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const STOCK_ADJUSTED = 'stock_adjusted';

    public const ACTIONS = [self::CREATED, self::UPDATED, self::DELETED, self::STOCK_ADJUSTED];

    public const SUBJECT_TYPES = ['Product', 'ProductCategory'];

    /**
     * Columns that say nothing about what a person did. Ids and timestamps are
     * noise in a report whose whole job is to read like a sentence.
     */
    private const IGNORED = ['id', 'user_id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Any field whose name contains one of these never reaches the trail, whatever
     * model it is on. The catalogue has no such column today — this is here so
     * that pointing the logger at a model that does (a user, an API credential)
     * cannot quietly turn an audit table into a plaintext secret store.
     */
    private const SENSITIVE = ['password', 'token', 'secret', 'signature', 'api_key', 'private_key'];

    public function created(User $actor, Model $subject): void
    {
        $this->write($actor, self::CREATED, $subject, $this->snapshot($subject));
    }

    /**
     * Call after the save, passing the snapshot() taken before it. Eloquent's own
     * dirty tracking decides which columns actually moved, so a PUT that resends
     * the record unchanged records nothing at all.
     *
     * @param  array<string, mixed>  $before
     */
    public function updated(User $actor, Model $subject, array $before): void
    {
        $after = $this->snapshot($subject);
        $diff = [];

        foreach (array_keys($subject->getChanges()) as $field) {
            if ($this->isIgnored($field)) {
                continue;
            }

            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            // Eloquent compares raw column values; a cast can still round-trip
            // both sides to the same thing ("5" vs 5), and logging 5 → 5 would
            // just teach the reader to distrust the report.
            if ($this->equivalent($from, $to)) {
                continue;
            }

            $diff[$field] = ['from' => $from, 'to' => $to];
        }

        if ($diff === []) {
            return;
        }

        $this->write($actor, self::UPDATED, $subject, $diff);
    }

    /**
     * Call with the still-populated model straight after delete() — the row is
     * gone, so this snapshot is the only remaining record of what it held.
     */
    public function deleted(User $actor, Model $subject): void
    {
        $this->write($actor, self::DELETED, $subject, $this->snapshot($subject));
    }

    /**
     * Stock has its own action because it does not move through update(): sales,
     * refunds and the adjust endpoint are the only paths, and the reason for a
     * manual correction matters as much as the number.
     */
    public function stockAdjusted(
        User $actor,
        Product $product,
        float $from,
        float $to,
        string $type,
        ?string $note = null,
        ?string $reason = null,
    ): void {
        $changes = [
            'stock_quantity' => ['from' => $from, 'to' => $to],
            'type' => $type,
        ];

        // "Adjustment" answers nothing on its own. Whether ten kilos were
        // spoiled, stolen or simply miscounted last week is the entire question
        // when someone reads this trail back.
        if (filled($reason)) {
            $changes['reason'] = $reason;
        }

        if (filled($note)) {
            $changes['note'] = Str::limit($note, 255, '');
        }

        $this->write($actor, self::STOCK_ADJUSTED, $product, $changes);
    }

    /**
     * Cast attribute values, minus everything the trail has no business keeping.
     * Take one before a save and hand it back to updated().
     *
     * @return array<string, mixed>
     */
    public function snapshot(Model $subject): array
    {
        $attributes = $subject->attributesToArray();

        return array_filter(
            $attributes,
            fn (string $field) => ! $this->isIgnored($field),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Which shop a row belongs to. Staff carry shop_id directly; a shop admin may
     * only be linked through the shop they own, and both have to resolve to the
     * same id or an owner would not see their own staff's trail.
     */
    public function shopIdFor(User $actor): ?int
    {
        if ($actor->shop_id) {
            return (int) $actor->shop_id;
        }

        $owned = $actor->ownedShop()->first();

        return $owned?->id;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function write(User $actor, string $action, Model $subject, array $changes): void
    {
        try {
            ActivityLog::create([
                'user_id' => $actor->id,
                'shop_id' => $this->shopIdFor($actor),
                // Denormalised on purpose: the report has to still name the
                // actor after the account is deleted.
                'user_name' => Str::limit((string) $actor->name, 160, ''),
                'action' => $action,
                'subject_type' => class_basename($subject),
                'subject_id' => $subject->getKey(),
                'subject_label' => $this->labelFor($subject),
                'changes' => $changes === [] ? null : $changes,
                'ip_address' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            // The trail is a witness to the write, not part of it. Letting an
            // insert failure here bubble would roll back a legitimate price
            // change — a worse outcome than a gap in the history. So it is
            // swallowed, but never silently: this lands in the app log with
            // everything needed to reconstruct the missing row.
            Log::error('Activity log write failed', [
                'action' => $action,
                'subject_type' => class_basename($subject),
                'subject_id' => $subject->getKey(),
                'actor_id' => $actor->id,
                'exception' => $e,
            ]);
        }
    }

    private function labelFor(Model $subject): ?string
    {
        $name = $subject->getAttribute('name');

        return filled($name) ? Str::limit((string) $name, 191, '') : null;
    }

    private function isIgnored(string $field): bool
    {
        if (in_array($field, self::IGNORED, true)) {
            return true;
        }

        return Str::contains(Str::lower($field), self::SENSITIVE);
    }

    private function equivalent(mixed $from, mixed $to): bool
    {
        if (is_numeric($from) && is_numeric($to)) {
            return (float) $from === (float) $to;
        }

        return $from === $to;
    }
}
