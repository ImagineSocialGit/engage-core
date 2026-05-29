<?php

namespace App\Models;

use App\Actions\Caching\FlushWebinarCachesAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webinar extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_series_id',
        'title',
        'slug',
        'platform',
        'external_id',
        'host_account_key',
        'join_url',
        'registration_url',
        'starts_at',
        'ends_at',
        'timezone',
        'description',
        'meta',
        'provider_settings',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'meta' => 'array',
        'provider_settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (Webinar $webinar): void {
            if (! $webinar->wasChanged([
                'starts_at',
                'ends_at',
                'webinar_series_id',
                'registration_url',
                'join_url',
                'timezone',
            ])) {
                return;
            }

            app(FlushWebinarCachesAction::class)->handle($webinar);
        });

        static::deleted(function (Webinar $webinar): void {
            app(FlushWebinarCachesAction::class)->handle($webinar);
        });
    }

    public function webinarSeries(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

}
