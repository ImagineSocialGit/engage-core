<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\AppointmentLifecycleEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class AppointmentLifecycleEvent extends Model
{
    use HasFactory;

    public const EVENT_CREATED = 'created';
    public const EVENT_SCHEDULED = 'scheduled';
    public const EVENT_CONFIRMED = 'confirmed';
    public const EVENT_RESCHEDULED = 'rescheduled';
    public const EVENT_CANCELED = 'canceled';
    public const EVENT_COMPLETED = 'completed';
    public const EVENT_NO_SHOW = 'no_show';

    protected $attributes = [
        'source' => 'system',
    ];

    protected $fillable = [
        'appointment_id',
        'event_id',
        'event_key',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'source',
        'reason',
        'context',
        'occurred_at',
    ];

    protected static function newFactory(): AppointmentLifecycleEventFactory
    {
        return AppointmentLifecycleEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! is_string($event->event_id) || trim($event->event_id) === '') {
                $event->event_id = (string) Str::uuid();
            } else {
                $event->event_id = trim($event->event_id);
            }

            $event->occurred_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'appointment_id' => 'integer',
            'actor_id' => 'integer',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}