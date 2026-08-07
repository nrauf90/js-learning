<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    public const PAYMENT_STATUSES = ['paid', 'partial', 'unpaid'];

    protected $fillable = [
        'user_id',
        'supplier_id',
        'reference',
        'invoice_number',
        'purchase_date',
        'subtotal',
        'discount_amount',
        'total',
        'amount_paid',
        'payment_status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Instalments paid against this invoice, oldest first.
     *
     * The id breaks the tie because `paid_at` is only second-precision: two
     * payments entered in the same breath would otherwise come back in whatever
     * order the database felt like, and the running balance printed beside them
     * would be wrong.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** What the shop still owes the wholesaler on this delivery. */
    public function outstandingAmount(): float
    {
        return max(0, round((float) $this->total - (float) $this->amount_paid, 2));
    }

    /**
     * The single place the three statuses are decided, so the stock-in screen
     * and any later settlement cannot disagree about what "partial" means.
     */
    public function resolvePaymentStatus(): string
    {
        if ($this->outstandingAmount() <= 0) {
            return 'paid';
        }

        return (float) $this->amount_paid > 0 ? 'partial' : 'unpaid';
    }
}
