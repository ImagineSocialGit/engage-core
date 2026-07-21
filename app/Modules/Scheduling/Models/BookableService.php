<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\BookableServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookableService extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'slot_interval_minutes' => 15,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'minimum_notice_minutes' => 0,
        'booking_horizon_days' => 60,
        'cancellation_notice_minutes' => 0,
        'reschedule_notice_minutes' => 0,
        'timezone' => 'UTC',
        'capacity' => 1,
        'requires_confirmation' => false,
        'is_public' => false,
        'sort_order' => 0,
        'source' => 'manual',
    ];

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'duration_minutes',
        'slot_interval_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'minimum_notice_minutes',
        'booking_horizon_days',
        'cancellation_notice_minutes',
        'reschedule_notice_minutes',
        'timezone',
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

    protected static function newFactory(): BookableServiceFactory
    {
        return BookableServiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'slot_interval_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'minimum_notice_minutes' => 'integer',
            'booking_horizon_days' => 'integer',
            'cancellation_notice_minutes' => 'integer',
            'reschedule_notice_minutes' => 'integer',
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

    public function hostAssignments(): HasMany
    {
        return $this->hasMany(BookableServiceHost::class);
    }

    public function schedulingHosts(): BelongsToMany
    {
        return $this->belongsToMany(
            SchedulingHost::class,
            'bookable_service_hosts',
        )
            ->as('assignment')
            ->withPivot([
                'id',
                'is_active',
                'capacity_override',
                'sort_order',
                'meta',
            ])
            ->withTimestamps();
    }
}