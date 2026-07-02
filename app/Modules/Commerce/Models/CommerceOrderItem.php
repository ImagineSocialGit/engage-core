<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceOrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): CommerceOrderItemFactory
    {
        return CommerceOrderItemFactory::new();
    }

    public const TYPE_PRODUCT = 'product';
    public const TYPE_SERVICE = 'service';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_TAX = 'tax';
    public const TYPE_FEE = 'fee';

    protected $fillable = [
        'commerce_order_id',
        'commerce_product_id',
        'item_type',
        'sku',
        'name',
        'title',
        'variant_title',
        'options',
        'quantity',
        'currency',
        'unit_price_cents',
        'discount_cents',
        'tax_cents',
        'total_cents',
        'fulfillment_status',
        'source',
        'provider',
        'external_id',
        'external_product_id',
        'external_variant_id',
        'external_url',
        'raw_payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_order_id' => 'integer',
            'commerce_product_id' => 'integer',
            'options' => 'array',
            'quantity' => 'decimal:4',
            'unit_price_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function commerceProduct(): BelongsTo
    {
        return $this->belongsTo(CommerceProduct::class);
    }
}
