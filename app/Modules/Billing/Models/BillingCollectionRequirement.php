<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCollectionRequirement extends Model
{
    public const MODE_NONE = 'none';
    public const MODE_FULL = 'full';
    public const MODE_FIXED_DEPOSIT = 'fixed_deposit';
    public const MODE_PERCENTAGE_DEPOSIT = 'percentage_deposit';
    public const MODE_PAY_LATER = 'pay_later';

    protected $fillable = [
        'billing_price_id',
        'mode',
        'fixed_amount_cents',
        'percentage_basis_points',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'billing_price_id' => 'integer',
            'fixed_amount_cents' => 'integer',
            'percentage_basis_points' => 'integer',
            'meta' => 'array',
        ];
    }

    public function billingPrice(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class);
    }
}