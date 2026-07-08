<?php

namespace App\Modules\Webinars\Models;

use Database\Factories\WebinarScheduleProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarScheduleProfile extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected static function newFactory(): WebinarScheduleProfileFactory
    {
        return WebinarScheduleProfileFactory::new();
    }

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'is_default',
        'is_active',
        'source',
        'source_config_path',
        'source_version',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'source_version' => 'integer',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WebinarScheduleProfileItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
