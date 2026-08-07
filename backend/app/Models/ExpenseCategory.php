<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /**
     * Categories the day book posts against on the shop's behalf.
     *
     * They are created on first till open/close, so they exist as real rows and
     * their names must still render on the entries they own — but they are
     * deliberately kept out of the manual picker. A shopkeeper hand-filing an
     * expense under "Till float" would land it in the drawer reconciliation and
     * silently throw the day's variance out.
     *
     * @see \App\Services\Pos\DayBookService
     */
    public const INTERNAL_SLUGS = ['till-float', 'till-close'];

    protected $fillable = [
        'name',
        'slug',
        'kind',
        'icon',
        'is_system',
        // Without this, retiring a category by mass assignment silently does
        // nothing: the attribute is dropped and the column default puts it
        // straight back on the picker.
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(CashEntry::class, 'category_id');
    }

    /**
     * Categories a person may choose by hand.
     *
     * Retired ones are excluded as well as internal ones. They are deactivated
     * rather than deleted so the entries already filed against them keep a name
     * to report under — but a shop has no use for "Car Wash" or "Entertainment"
     * in its picker, and leaving them selectable would defeat the point of
     * retiring them at all.
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->whereNotIn('slug', self::INTERNAL_SLUGS)->where('is_active', true);
    }
}
