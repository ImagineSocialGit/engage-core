<?php

namespace App\Modules\Location\Models;

use Database\Factories\LocationAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocationArea extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): LocationAreaFactory
    {
        return LocationAreaFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const TYPE_MARKET = 'market';
    public const TYPE_REGION = 'region';
    public const TYPE_SERVICE_AREA = 'service_area';
    public const TYPE_TERRITORY = 'territory';
    public const TYPE_ZONE = 'zone';
    public const TYPE_CUSTOM = 'custom';

    public const BOUNDARY_TYPE_MANUAL = 'manual';
    public const BOUNDARY_TYPE_RADIUS = 'radius';
    public const BOUNDARY_TYPE_POSTAL_CODES = 'postal_codes';
    public const BOUNDARY_TYPE_REGIONS = 'regions';
    public const BOUNDARY_TYPE_POLYGON = 'polygon';

    protected $fillable = [
        'key',
        'name',
        'description',
        'type',
        'status',
        'boundary_type',
        'country',
        'region',
        'city',
        'postal_code',
        'center_latitude',
        'center_longitude',
        'radius_meters',
        'geometry',
        'timezone',
        'is_service_area',
        'source',
        'provider',
        'external_id',
        'external_url',
        'settings',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'geometry' => 'array',
            'is_service_area' => 'boolean',
            'settings' => 'array',
            'meta' => 'array',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LocationAreaAssignment::class);
    }
}
