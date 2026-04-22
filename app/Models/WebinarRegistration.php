<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebinarRegistration extends Model
{
    protected $fillable = [
        'lead_id',
        'webinar_id',
        'join_token',
        'webinar_slug',
        'status',
        'source',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'meta',
        'registered_at',
        'attended_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'registered_at' => 'datetime',
        'attended_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(WebinarScheduledMessage::class, 'webinar_registration_id');
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