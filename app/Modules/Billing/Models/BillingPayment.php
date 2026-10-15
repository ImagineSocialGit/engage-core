<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'billing_payment_request_id',
        'provider_key',
        'external_id',
        'status',
        'currency',
        'amount_cents',
        'occurred_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'billing_payment_request_id' => 'integer',
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function billingPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(BillingPaymentRequest::class);
    }
}