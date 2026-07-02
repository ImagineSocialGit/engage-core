<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceProduct extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): CommerceProductFactory
    {
        return CommerceProductFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key',
        'sku',
        'name',
        'description',
        'status',
        'product_type',
        'vendor',
        'category',
        'tags',
        'currency',
        'price_cents',
        'published_at',
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
            'tags' => 'array',
            'price_cents' => 'integer',
            'published_at' => 'datetime',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class);
    }
}
