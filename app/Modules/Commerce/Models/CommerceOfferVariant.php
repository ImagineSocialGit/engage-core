<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceOfferVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceOfferVariant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected static function newFactory(): CommerceOfferVariantFactory
    {
        return CommerceOfferVariantFactory::new();
    }

    protected $fillable = [
        'commerce_offer_id',
        'commerce_product_variant_id',
        'status',
        'is_default',
        'position',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_offer_id' => 'integer',
            'commerce_product_variant_id' => 'integer',
            'is_default' => 'boolean',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    public function commerceOffer(): BelongsTo
    {
        return $this->belongsTo(CommerceOffer::class);
    }

    public function commerceProductVariant(): BelongsTo
    {
        return $this->belongsTo(CommerceProductVariant::class);
    }
}