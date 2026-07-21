<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use Database\Factories\WebinarSeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebinarSeries extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarSeriesFactory
    {
        return WebinarSeriesFactory::new();
    }

    protected $fillable = [
        'title',
        'slug',
        'status',
        'platform',
        'provider_event_type',
        'webinar_schedule_profile_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (WebinarSeries $series): void {
            if (blank($series->slug)) {
                $series->slug = Str::slug($series->title);
            }

            if (blank($series->platform)) {
                $series->platform = static::configuredProviderKey();
            }

            $series->provider_event_type = WebinarProviderEventType::normalize(
                $series->provider_event_type
                    ?? config('webinars.provider_event_type'),
            );
        });

        static::saved(function (WebinarSeries $series): void {
            if (! $series->wasChanged([
                'slug',
                'title',
                'status',
                'platform',
                'provider_event_type',
                'webinar_schedule_profile_id',
                'meta',
            ])) {
                return;
            }

            app(FlushWebinarCachesAction::class)
                ->handle(seriesSlug: $series->slug);
        });

        static::deleted(function (WebinarSeries $series): void {
            app(FlushWebinarCachesAction::class)
                ->handle(seriesSlug: $series->slug);
        });
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class, 'webinar_series_id');
    }

    public function webinarScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WebinarScheduleProfile::class);
    }

    public function providerKey(): string
    {
        $provider = is_string($this->platform)
            ? strtolower(trim($this->platform))
            : '';

        return $provider !== ''
            ? $provider
            : static::configuredProviderKey();
    }

    public function providerEventTypeKey(): string
    {
        return WebinarProviderEventType::normalize(
            $this->provider_event_type
                ?? config('webinars.provider_event_type'),
        );
    }

    private static function configuredProviderKey(): string
    {
        $provider = config('webinars.provider', 'zoom');

        return is_string($provider) && trim($provider) !== ''
            ? strtolower(trim($provider))
            : 'zoom';
    }
}