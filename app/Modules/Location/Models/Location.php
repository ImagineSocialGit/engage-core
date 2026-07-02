<?php

namespace App\Modules\Location\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const TYPE_ADDRESS = 'address';
    public const TYPE_PLACE = 'place';
    public const TYPE_VIRTUAL = 'virtual';
    public const TYPE_REGION = 'region';

    protected $fillable = [
        'key',
        'name',
        'label',
        'type',
        'status',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
        'formatted_address',
        'latitude',
        'longitude',
        'timezone',
        'precision',
        'confidence',
        'source',
        'provider',
        'external_id',
        'external_url',
        'geocoded_at',
        'raw_payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'confidence' => 'decimal:4',
            'geocoded_at' => 'datetime',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function contactLocations(): HasMany
    {
        return $this->hasMany(ContactLocation::class);
    }

    public function areaAssignments(): HasMany
    {
        return $this->hasMany(LocationAreaAssignment::class);
    }
}
