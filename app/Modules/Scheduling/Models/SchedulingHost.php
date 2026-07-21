<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\SchedulingHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchedulingHost extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PROVIDER = 'provider';

    protected $fillable = [
        'key',
        'name',
        'status',
        'hostable_type',
        'hostable_id',
        'timezone',
        'capacity',
        'email',
        'phone',
        'sort_order',
        'source',
        'meta',
    ];

    protected static function newFactory(): SchedulingHostFactory
    {
        return SchedulingHostFactory::new();
    }

    protected function casts(): array
    {
        return [
            'hostable_id' => 'integer',
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function hostable(): MorphTo
    {
        return $this->morphTo();
    }

    public function serviceAssignments(): HasMany
    {
        return $this->hasMany(BookableServiceHost::class);
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(SchedulingAvailabilityWindow::class);
    }

    public function hostWideAvailabilityWindows(): HasMany
    {
        return $this->availabilityWindows()
            ->whereNull('bookable_service_id');
    }

    public function serviceScopedAvailabilityWindows(): HasMany
    {
        return $this->availabilityWindows()
            ->whereNotNull('bookable_service_id');
    }

    public function bookableServices(): BelongsToMany
    {
        return $this->belongsToMany(
            BookableService::class,
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