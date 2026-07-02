<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\AppointmentAttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppointmentAttendee extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): AppointmentAttendeeFactory
    {
        return AppointmentAttendeeFactory::new();
    }

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_TENTATIVE = 'tentative';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'appointment_id',
        'attendee_type',
        'attendee_id',
        'contact_id',
        'name',
        'email',
        'phone',
        'role',
        'status',
        'responded_at',
        'joined_at',
        'canceled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'appointment_id' => 'integer',
            'attendee_id' => 'integer',
            'contact_id' => 'integer',
            'responded_at' => 'datetime',
            'joined_at' => 'datetime',
            'canceled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function attendee(): MorphTo
    {
        return $this->morphTo();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
