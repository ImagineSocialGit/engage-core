<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Database\Factories\WebinarRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class WebinarRegistration extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarRegistrationFactory
    {
        return WebinarRegistrationFactory::new();
    }

    protected $fillable = [
        'contact_id',
        'webinar_id',
        'replacement_of_registration_id',
        'join_token',
        'webinar_slug',
        'status',
        'source',
        'meta',
        'registered_at',
        'attended_at',
        'cancelled_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'registered_at' => 'datetime',
        'attended_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function replacementOfRegistration(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_registration_id');
    }

    public function replacementRegistration(): HasOne
    {
        return $this->hasOne(self::class, 'replacement_of_registration_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(
            WebinarRegistrationResponse::class,
            'webinar_registration_id',
        )->orderBy('sort_order')->orderBy('id');
    }

    public function scheduledMessages(): MorphMany
    {
        return $this->morphMany(ScheduledMessage::class, 'context');
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration): void {
            if (blank($registration->join_token)) {
                $registration->join_token = static::generateJoinToken();
            }
        });
    }

    public static function generateJoinToken(): string
    {
        do {
            $token = Str::lower(Str::random(16));
        } while (static::query()->where('join_token', $token)->exists());

        return $token;
    }
}