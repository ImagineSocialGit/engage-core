<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\CommerceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceOrder extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): CommerceOrderFactory
    {
        return CommerceOrderFactory::new();
    }

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ARCHIVED = 'archived';

    public const FINANCIAL_STATUS_PENDING = 'pending';
    public const FINANCIAL_STATUS_AUTHORIZED = 'authorized';
    public const FINANCIAL_STATUS_PAID = 'paid';
    public const FINANCIAL_STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const FINANCIAL_STATUS_REFUNDED = 'refunded';
    public const FINANCIAL_STATUS_VOIDED = 'voided';

    public const FULFILLMENT_STATUS_UNFULFILLED = 'unfulfilled';
    public const FULFILLMENT_STATUS_PARTIAL = 'partial';
    public const FULFILLMENT_STATUS_FULFILLED = 'fulfilled';
    public const FULFILLMENT_STATUS_RESTOCKED = 'restocked';

    protected $fillable = [
        'commerce_customer_id',
        'contact_id',
        'order_number',
        'order_name',
        'status',
        'financial_status',
        'fulfillment_status',
        'currency',
        'subtotal_cents',
        'discount_cents',
        'tax_cents',
        'shipping_cents',
        'total_cents',
        'ordered_at',
        'closed_at',
        'cancelled_at',
        'refunded_at',
        'source',
        'provider',
        'external_id',
        'external_url',
        'raw_payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_customer_id' => 'integer',
            'contact_id' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'ordered_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function commerceCustomer(): BelongsTo
    {
        return $this->belongsTo(CommerceCustomer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommerceOrderEvent::class);
    }
}
