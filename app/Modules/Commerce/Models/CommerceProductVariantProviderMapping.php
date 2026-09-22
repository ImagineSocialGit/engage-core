<?php

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceProductVariantProviderMapping extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'commerce_product_variant_id',
        'provider_key',
        'reference_type',
        'external_id',
        'external_parent_id',
        'external_url',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_product_variant_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function commerceProductVariant(): BelongsTo
    {
        return $this->belongsTo(CommerceProductVariant::class);
    }

    public function inventoryAdjustments(): HasMany
    {
        return $this->hasMany(CommerceInventoryAdjustment::class);
    }
}