<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    protected $attributes = [
        'status' => EventStatus::Draft->value,
        'attendance_mode' => EventAttendanceMode::Physical->value,
        'timezone' => 'UTC',
    ];

    protected $fillable = [
        'type_key',
        'title',
        'description',
        'status',
        'attendance_mode',
        'starts_at',
        'ends_at',
        'timezone',
        'announcement_at',
        'venue_name',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country',
        'primary_external_reference_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'attendance_mode' => EventAttendanceMode::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'announcement_at' => 'datetime',
            'primary_external_reference_id' => 'integer',
        ];
    }

    public function externalReferences(): HasMany
    {
        return $this->hasMany(EventExternalReference::class);
    }

    public function primaryExternalReference(): BelongsTo
    {
        return $this->belongsTo(
            EventExternalReference::class,
            'primary_external_reference_id',
        );
    }
}