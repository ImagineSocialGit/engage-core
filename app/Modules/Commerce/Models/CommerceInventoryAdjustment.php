<?php

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceInventoryAdjustment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'commerce_inventory_effect_id',
        'commerce_product_variant_provider_mapping_id',
        'provider_key',
        'quantity_delta',
        'status',
        'idempotency_key',
        'external_id',
        'requested_at',
        'completed_at',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_inventory_effect_id' => 'integer',
            'commerce_product_variant_provider_mapping_id' => 'integer',
            'quantity_delta' => 'decimal:4',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function inventoryEffect(): BelongsTo
    {
        return $this->belongsTo(
            CommerceInventoryEffect::class,
            'commerce_inventory_effect_id',
        );
    }

    public function providerMapping(): BelongsTo
    {
        return $this->belongsTo(
            CommerceProductVariantProviderMapping::class,
            'commerce_product_variant_provider_mapping_id',
        );
    }
}