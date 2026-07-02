<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\BookableServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookableService extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): BookableServiceFactory
    {
        return BookableServiceFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'location_type',
        'location_details',
        'capacity',
        'requires_confirmation',
        'is_public',
        'sort_order',
        'source',
        'provider',
        'external_id',
        'external_url',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'location_details' => 'array',
            'capacity' => 'integer',
            'requires_confirmation' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(SchedulingAvailabilityWindow::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
