<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A photograph of paperwork — a supplier's bill, or the screenshot of a
 * transfer — filed against the record it evidences.
 *
 * `path` and `user_id` are deliberately outside mass assignment. The path is
 * chosen by ImageStore from the decoded image header and never by the caller,
 * and user_id is the shop boundary every read is checked against; a controller
 * that ever passed unfiltered input into create() would otherwise be able to
 * file one shop's receipt under another's, or point a row at a file outside the
 * receipts disk.
 */
class Attachment extends Model
{
    protected $fillable = [
        'uploaded_by',
        'original_name',
        'mime',
        'size_bytes',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The shop this belongs to — see User::dataOwnerId(). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
