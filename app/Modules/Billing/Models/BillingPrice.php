<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingPrice extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'billable_type',
        'billable_id',
        'key',
        'status',
        'currency',
        'amount_cents',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'billable_id' => 'integer',
            'amount_cents' => 'integer',
            'meta' => 'array',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function collectionRequirement(): HasOne
    {
        return $this->hasOne(BillingCollectionRequirement::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(BillingPaymentRequest::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}