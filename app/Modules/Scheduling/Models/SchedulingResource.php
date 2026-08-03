<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchedulingResource extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PROVIDER = 'provider';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'source' => self::SOURCE_MANUAL,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'key',
        'name',
        'status',
        'source',
        'sort_order',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function hostCapacities(): HasMany
    {
        return $this->hasMany(SchedulingHostResource::class);
    }

    public function serviceRequirements(): HasMany
    {
        return $this->hasMany(BookableServiceResourceRequirement::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(SchedulingResourceOccupancy::class);
    }
}