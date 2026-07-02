<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\SchedulingAvailabilityWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchedulingAvailabilityWindow extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): SchedulingAvailabilityWindowFactory
    {
        return SchedulingAvailabilityWindowFactory::new();
    }

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PROVIDER = 'provider';

    protected $fillable = [
        'bookable_service_id',
        'owner_type',
        'owner_id',
        'timezone',
        'weekday',
        'starts_at',
        'ends_at',
        'start_time',
        'end_time',
        'capacity',
        'rrule',
        'is_available',
        'source',
        'provider',
        'external_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'owner_id' => 'integer',
            'weekday' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'is_available' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
