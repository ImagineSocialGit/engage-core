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

    public const DURATION_MODE_FIXED = 'fixed';
    public const DURATION_MODE_RANGE = 'range';

    public const DURATION_MODES = [
        self::DURATION_MODE_FIXED,
        self::DURATION_MODE_RANGE,
    ];

    public const MAX_RANGE_DURATION_MINUTES = 366 * 1440;

    public const APPOINTMENT_FORMAT_IN_PERSON = 'in_person';
    public const APPOINTMENT_FORMAT_REMOTE = 'remote';

    public const APPOINTMENT_FORMATS = [
        self::APPOINTMENT_FORMAT_IN_PERSON,
        self::APPOINTMENT_FORMAT_REMOTE,
    ];

    public const IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION = 'business_location';
    public const IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS = 'customer_address';

    public const IN_PERSON_ARRANGEMENTS = [
        self::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION,
        self::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS,
    ];

    public const REMOTE_METHOD_PHONE = 'phone';
    public const REMOTE_METHOD_VIRTUAL_MEETING = 'virtual_meeting';

    public const REMOTE_METHODS = [
        self::REMOTE_METHOD_PHONE,
        self::REMOTE_METHOD_VIRTUAL_MEETING,
    ];

    /**
     * Runtime commitment classifications retained for durable appointment,
     * offer, hold, travel, and historical snapshot compatibility.
     *
     * Service authoring must use appointment_format plus the applicable
     * arrangement/method field. SchedulingConfigurationWriter derives this
     * internal value from that business-language configuration.
     */
    public const LOCATION_TYPE_PHONE = 'phone';
    public const LOCATION_TYPE_VIRTUAL = 'virtual';
    public const LOCATION_TYPE_FIXED = 'fixed';
    public const LOCATION_TYPE_CUSTOMER_SITE = 'customer_site';

    public const LOCATION_TYPES = [
        self::LOCATION_TYPE_PHONE,
        self::LOCATION_TYPE_VIRTUAL,
        self::LOCATION_TYPE_FIXED,
        self::LOCATION_TYPE_CUSTOMER_SITE,
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'duration_mode' => self::DURATION_MODE_FIXED,
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
        'duration_mode',
        'duration_minutes',
        'minimum_duration_minutes',
        'maximum_duration_minutes',
        'slot_interval_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'minimum_notice_minutes',
        'booking_horizon_days',
        'cancellation_notice_minutes',
        'reschedule_notice_minutes',
        'timezone',
        'appointment_format',
        'in_person_arrangement',
        'remote_method',
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

    public function usesFixedDuration(): bool
    {
        return $this->duration_mode !== self::DURATION_MODE_RANGE;
    }

    public function usesRangeDuration(): bool
    {
        return $this->duration_mode === self::DURATION_MODE_RANGE;
    }

    public function defaultDurationMinutes(): int
    {
        return max(1, (int) $this->duration_minutes);
    }

    public function minimumDurationMinutes(): int
    {
        if ($this->usesFixedDuration()) {
            return $this->defaultDurationMinutes();
        }

        return max(
            1,
            (int) ($this->minimum_duration_minutes ?? $this->defaultDurationMinutes()),
        );
    }

    public function maximumDurationMinutes(): int
    {
        if ($this->usesFixedDuration()) {
            return $this->defaultDurationMinutes();
        }

        return max(
            1,
            (int) ($this->maximum_duration_minutes ?? $this->defaultDurationMinutes()),
        );
    }

    public function hasValidDurationPolicy(): bool
    {
        if (! in_array($this->duration_mode, self::DURATION_MODES, true)) {
            return false;
        }

        if ($this->usesFixedDuration()) {
            return true;
        }

        $minimum = $this->minimumDurationMinutes();
        $maximum = $this->maximumDurationMinutes();
        $default = $this->defaultDurationMinutes();

        return $minimum <= $maximum
            && $maximum <= self::MAX_RANGE_DURATION_MINUTES
            && $default >= $minimum
            && $default <= $maximum;
    }

    public function allowsDurationMinutes(int $durationMinutes): bool
    {
        return $this->hasValidDurationPolicy()
            && $durationMinutes >= $this->minimumDurationMinutes()
            && $durationMinutes <= $this->maximumDurationMinutes();
    }

    public function hasCompleteAppointmentFormat(): bool
    {
        $configuration = $this->resolvedAppointmentConfiguration();

        return $configuration['appointment_format'] !== null
            && $configuration['location_type'] !== null
            && $configuration['location_type'] === $this->location_type;
    }

    public function appointmentFormatLabel(): ?string
    {
        return match ($this->resolvedAppointmentConfiguration()['appointment_format']) {
            self::APPOINTMENT_FORMAT_IN_PERSON => 'In person',
            self::APPOINTMENT_FORMAT_REMOTE => 'Remote',
            default => null,
        };
    }

    public function appointmentMethodLabel(): ?string
    {
        $configuration = $this->resolvedAppointmentConfiguration();

        return match (true) {
            $configuration['appointment_format'] === self::APPOINTMENT_FORMAT_IN_PERSON
                && $configuration['in_person_arrangement'] === self::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION => 'Business location',
            $configuration['appointment_format'] === self::APPOINTMENT_FORMAT_IN_PERSON
                && $configuration['in_person_arrangement'] === self::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS => 'Customer-provided address',
            $configuration['appointment_format'] === self::APPOINTMENT_FORMAT_REMOTE
                && $configuration['remote_method'] === self::REMOTE_METHOD_PHONE => 'Phone call',
            $configuration['appointment_format'] === self::APPOINTMENT_FORMAT_REMOTE
                && $configuration['remote_method'] === self::REMOTE_METHOD_VIRTUAL_MEETING => 'Virtual meeting',
            default => null,
        };
    }

    /**
     * @return array{appointment_format: string|null, in_person_arrangement: string|null, remote_method: string|null, location_type: string|null}
     */
    public function resolvedAppointmentConfiguration(): array
    {
        $locationType = self::locationTypeForAppointmentConfiguration(
            appointmentFormat: $this->appointment_format,
            inPersonArrangement: $this->in_person_arrangement,
            remoteMethod: $this->remote_method,
        );

        if ($locationType !== null) {
            return [
                'appointment_format' => $this->appointment_format,
                'in_person_arrangement' => $this->in_person_arrangement,
                'remote_method' => $this->remote_method,
                'location_type' => $locationType,
            ];
        }

        if ($this->appointment_format === null
            && $this->in_person_arrangement === null
            && $this->remote_method === null
        ) {
            return [
                ...self::appointmentConfigurationForLocationType($this->location_type),
                'location_type' => in_array($this->location_type, self::LOCATION_TYPES, true)
                    ? $this->location_type
                    : null,
            ];
        }

        return [
            'appointment_format' => null,
            'in_person_arrangement' => null,
            'remote_method' => null,
            'location_type' => null,
        ];
    }

    public static function locationTypeForAppointmentConfiguration(
        ?string $appointmentFormat,
        ?string $inPersonArrangement,
        ?string $remoteMethod,
    ): ?string {
        return match (true) {
            $appointmentFormat === self::APPOINTMENT_FORMAT_IN_PERSON
                && $inPersonArrangement === self::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION => self::LOCATION_TYPE_FIXED,
            $appointmentFormat === self::APPOINTMENT_FORMAT_IN_PERSON
                && $inPersonArrangement === self::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS => self::LOCATION_TYPE_CUSTOMER_SITE,
            $appointmentFormat === self::APPOINTMENT_FORMAT_REMOTE
                && $remoteMethod === self::REMOTE_METHOD_PHONE => self::LOCATION_TYPE_PHONE,
            $appointmentFormat === self::APPOINTMENT_FORMAT_REMOTE
                && $remoteMethod === self::REMOTE_METHOD_VIRTUAL_MEETING => self::LOCATION_TYPE_VIRTUAL,
            default => null,
        };
    }

    /**
     * @return array{appointment_format: string|null, in_person_arrangement: string|null, remote_method: string|null}
     */
    public static function appointmentConfigurationForLocationType(?string $locationType): array
    {
        return match ($locationType) {
            self::LOCATION_TYPE_FIXED => [
                'appointment_format' => self::APPOINTMENT_FORMAT_IN_PERSON,
                'in_person_arrangement' => self::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION,
                'remote_method' => null,
            ],
            self::LOCATION_TYPE_CUSTOMER_SITE => [
                'appointment_format' => self::APPOINTMENT_FORMAT_IN_PERSON,
                'in_person_arrangement' => self::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS,
                'remote_method' => null,
            ],
            self::LOCATION_TYPE_PHONE => [
                'appointment_format' => self::APPOINTMENT_FORMAT_REMOTE,
                'in_person_arrangement' => null,
                'remote_method' => self::REMOTE_METHOD_PHONE,
            ],
            self::LOCATION_TYPE_VIRTUAL => [
                'appointment_format' => self::APPOINTMENT_FORMAT_REMOTE,
                'in_person_arrangement' => null,
                'remote_method' => self::REMOTE_METHOD_VIRTUAL_MEETING,
            ],
            default => [
                'appointment_format' => null,
                'in_person_arrangement' => null,
                'remote_method' => null,
            ],
        };
    }

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'minimum_duration_minutes' => 'integer',
            'maximum_duration_minutes' => 'integer',
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

    public function serviceWideAvailabilityWindows(): HasMany
    {
        return $this->availabilityWindows()
            ->whereNull('scheduling_host_id');
    }

    public function hostScopedAvailabilityWindows(): HasMany
    {
        return $this->availabilityWindows()
            ->whereNotNull('scheduling_host_id');
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