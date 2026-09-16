<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingBookingOfferClaim extends Model
{
    protected $fillable = [
        'scheduling_booking_offer_id',
        'appointment_id',
        'contact_id',
        'qualification_scope_key',
        'claim_number',
        'qualification_meta',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduling_booking_offer_id' => 'integer',
            'appointment_id' => 'integer',
            'contact_id' => 'integer',
            'claim_number' => 'integer',
            'qualification_meta' => 'array',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    public function bookingOffer(): BelongsTo
    {
        return $this->belongsTo(
            SchedulingBookingOffer::class,
            'scheduling_booking_offer_id',
        );
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}