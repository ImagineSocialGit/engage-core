<?php

namespace App\Models;

use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class WebinarRegistration extends Model
{

    use HasFactory;

    protected $fillable = [
        'contact_id',
        'webinar_id',
        'join_token',
        'webinar_slug',
        'status',
        'source',
        'ip_address',
        'user_agent',
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
