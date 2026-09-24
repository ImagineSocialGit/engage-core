<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceProductVariant extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ARCHIVED = 'archived';

    protected static function newFactory(): CommerceProductVariantFactory
    {
        return CommerceProductVariantFactory::new();
    }

    protected $fillable = [
        'commerce_product_id',
        'key',
        'sku',
        'barcode',
        'title',
        'status',
        'options',
        'position',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_product_id' => 'integer',
            'options' => 'array',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    public function commerceProduct(): BelongsTo
    {
        return $this->belongsTo(CommerceProduct::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class);
    }

    public function providerMappings(): HasMany
    {
        return $this->hasMany(CommerceProductVariantProviderMapping::class);
    }

    public function offerVariants(): HasMany
    {
        return $this->hasMany(CommerceOfferVariant::class);
    }

    public function inventoryEffects(): HasMany
    {
        return $this->hasMany(CommerceInventoryEffect::class);
    }
}