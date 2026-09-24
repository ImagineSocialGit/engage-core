<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceOffer extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected static function newFactory(): CommerceOfferFactory
    {
        return CommerceOfferFactory::new();
    }

    protected $fillable = [
        'key',
        'slug',
        'title',
        'description',
        'status',
        'provider_scope',
        'position',
        'publish_starts_at',
        'publish_ends_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'publish_starts_at' => 'datetime',
            'publish_ends_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function offerVariants(): HasMany
    {
        return $this->hasMany(CommerceOfferVariant::class)
            ->orderBy('position')
            ->orderBy('id');
    }
}