<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_NO_SHOW = 'no_show';

    protected $fillable = [
        'bookable_service_id',
        'contact_id',
        'primary_attendee_type',
        'primary_attendee_id',
        'rescheduled_from_id',
        'status',
        'title',
        'description',
        'location_type',
        'location_details',
        'timezone',
        'starts_at',
        'ends_at',
        'confirmed_at',
        'completed_at',
        'no_show_at',
        'canceled_at',
        'cancellation_reason',
        'source',
        'provider',
        'external_id',
        'external_url',
        'created_by_type',
        'created_by_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'contact_id' => 'integer',
            'primary_attendee_id' => 'integer',
            'rescheduled_from_id' => 'integer',
            'location_details' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show_at' => 'datetime',
            'canceled_at' => 'datetime',
            'created_by_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function primaryAttendee(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'primary_attendee_type', 'primary_attendee_id');
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'created_by_type', 'created_by_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledAppointments(): HasMany
    {
        return $this->hasMany(self::class, 'rescheduled_from_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(AppointmentAttendee::class);
    }
}
