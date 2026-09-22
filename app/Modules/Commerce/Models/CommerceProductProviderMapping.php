<?php

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceProductProviderMapping extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'commerce_product_id',
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
            'commerce_product_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function commerceProduct(): BelongsTo
    {
        return $this->belongsTo(CommerceProduct::class);
    }
}