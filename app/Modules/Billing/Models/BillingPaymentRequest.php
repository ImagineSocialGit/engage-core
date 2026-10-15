<?php

namespace App\Modules\Billing\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingPaymentRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'billing_price_id',
        'contact_id',
        'context_type',
        'context_id',
        'status',
        'collection_mode',
        'currency',
        'total_cents',
        'due_now_cents',
        'paid_cents',
        'settled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'billing_price_id' => 'integer',
            'contact_id' => 'integer',
            'context_id' => 'integer',
            'total_cents' => 'integer',
            'due_now_cents' => 'integer',
            'paid_cents' => 'integer',
            'settled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function billingPrice(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function immediateRemainingCents(): int
    {
        return max(0, $this->due_now_cents - $this->paid_cents);
    }

    public function outstandingCents(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    public function immediateCollectionSatisfied(): bool
    {
        return $this->immediateRemainingCents() === 0;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED
            && $this->outstandingCents() === 0;
    }
}