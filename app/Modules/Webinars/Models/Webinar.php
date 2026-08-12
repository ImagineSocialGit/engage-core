<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Services\WebinarTimezoneResolver;
use Database\Factories\WebinarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Webinar extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarFactory
    {
        return WebinarFactory::new();
    }

    protected $fillable = [
        'webinar_series_id',
        'replacement_of_webinar_id',
        'webinar_schedule_profile_id',
        'title',
        'slug',
        'platform',
        'provider_event_type',
        'external_id',
        'host_account_key',
        'join_url',
        'registration_url',
        'playback_token',
        'playback_url',
        'playback_passcode',
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
        static::creating(function (Webinar $webinar): void {
            if (blank($webinar->platform)) {
                $webinar->platform = $webinar->webinarSeries?->providerKey()
                    ?? static::configuredProviderKey();
            }

            $webinar->provider_event_type = WebinarProviderEventType::normalize(
                $webinar->provider_event_type
                    ?? $webinar->webinarSeries?->providerEventTypeKey()
                    ?? config('webinars.provider_event_type'),
            );
        });

        static::saved(function (Webinar $webinar): void {
            if (
                ! $webinar->wasRecentlyCreated
                && ! $webinar->wasChanged([
                    'starts_at',
                    'ends_at',
                    'webinar_series_id',
                    'replacement_of_webinar_id',
                    'webinar_schedule_profile_id',
                    'platform',
                    'provider_event_type',
                    'registration_url',
                    'join_url',
                    'timezone',
                ])
            ) {
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

    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_webinar_id');
    }

    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replacement_of_webinar_id');
    }

    public function webinarScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WebinarScheduleProfile::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function scopeForSeriesProviderIdentity(
        Builder $query,
        WebinarSeries $series,
    ): Builder {
        return $query
            ->where('webinar_series_id', $series->getKey())
            ->where('platform', $series->providerKey())
            ->where('provider_event_type', $series->providerEventTypeKey());
    }

    public function scopeMatchingCurrentSeriesProvider(Builder $query): Builder
    {
        return $query->whereHas(
            'webinarSeries',
            fn (Builder $seriesQuery): Builder => $seriesQuery
                ->whereColumn('webinar_series.platform', 'webinars.platform')
                ->whereColumn(
                    'webinar_series.provider_event_type',
                    'webinars.provider_event_type',
                ),
        );
    }

    public function matchesSeriesProviderIdentity(?WebinarSeries $series = null): bool
    {
        $series ??= $this->relationLoaded('webinarSeries')
            ? $this->getRelation('webinarSeries')
            : $this->webinarSeries()->first();

        return $series instanceof WebinarSeries
            && (int) $this->webinar_series_id === (int) $series->getKey()
            && $this->providerKey() === $series->providerKey()
            && $this->providerEventTypeKey() === $series->providerEventTypeKey();
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

    public function getTimezoneAttribute(mixed $value): string
    {
        return app(WebinarTimezoneResolver::class)->resolve($value);
    }

    public function setTimezoneAttribute(mixed $value): void
    {
        $this->attributes['timezone'] = app(WebinarTimezoneResolver::class)
            ->resolve($value);
    }

    private static function configuredProviderKey(): string
    {
        $provider = config('webinars.provider', 'zoom');

        return is_string($provider) && trim($provider) !== ''
            ? strtolower(trim($provider))
            : 'zoom';
    }
}