<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Services\WebinarTimezoneResolver;
use Database\Factories\WebinarSeriesVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebinarSeriesVariant extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarSeriesVariantFactory
    {
        return WebinarSeriesVariantFactory::new();
    }

    protected $fillable = [
        'webinar_series_id',
        'key',
        'name',
        'public_slug',
        'timezone',
        'platform',
        'provider_event_type',
        'provider_match_title',
        'status',
        'is_default',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (WebinarSeriesVariant $variant): void {
            if (blank($variant->key)) {
                $variant->key = Str::slug($variant->name) ?: 'variant';
            }

            if (blank($variant->public_slug)) {
                $variant->public_slug = Str::slug($variant->name) ?: $variant->key;
            }

            if (blank($variant->timezone)) {
                $variant->timezone = app(WebinarTimezoneResolver::class)->resolve();
            }

            if (blank($variant->platform)) {
                $variant->platform = $variant->webinarSeries?->providerKey()
                    ?? static::configuredProviderKey();
            }

            $variant->provider_event_type = WebinarProviderEventType::normalize(
                $variant->provider_event_type
                    ?? $variant->webinarSeries?->providerEventTypeKey()
                    ?? config('webinars.provider_event_type'),
            );

            if (blank($variant->provider_match_title)) {
                $variant->provider_match_title = $variant->webinarSeries?->title
                    ?? $variant->name;
            }

            if ($variant->is_default) {
                $seriesSlug = trim((string) $variant->webinarSeries?->slug);
                $meta = is_array($variant->meta) ? $variant->meta : [];
                $originalSlug = data_get($meta, 'compatibility.original_public_slug');
                $originalSlug = is_string($originalSlug) && trim($originalSlug) !== ''
                    ? trim($originalSlug)
                    : $seriesSlug;

                if ($originalSlug !== '') {
                    $variant->public_slug = $originalSlug;
                    data_set($meta, 'compatibility.original_public_slug', $originalSlug);
                    data_set($meta, 'compatibility.inherited_series_slug', true);
                    $variant->meta = $meta;
                }
            }
        });

        static::saving(function (WebinarSeriesVariant $variant): void {
            if (! $variant->is_default) {
                return;
            }

            $variant->loadMissing('webinarSeries');
            $series = $variant->webinarSeries;
            $meta = is_array($variant->meta) ? $variant->meta : [];
            $originalSlug = data_get($meta, 'compatibility.original_public_slug');
            $originalSlug = is_string($originalSlug) && trim($originalSlug) !== ''
                ? trim($originalSlug)
                : trim((string) $series?->slug);

            if ($originalSlug !== '') {
                $variant->public_slug = $originalSlug;
                data_set($meta, 'compatibility.original_public_slug', $originalSlug);
                data_set($meta, 'compatibility.inherited_series_slug', true);
                $variant->meta = $meta;
            }

            if ($series?->status === 'active') {
                $variant->status = 'active';
            }
        });

        static::saved(function (WebinarSeriesVariant $variant): void {
            if (! $variant->wasChanged([
                'name',
                'public_slug',
                'timezone',
                'platform',
                'provider_event_type',
                'provider_match_title',
                'status',
                'is_default',
                'meta',
            ])) {
                return;
            }

            app(FlushWebinarCachesAction::class)->handle(
                seriesSlug: $variant->public_slug,
            );

            if ($variant->webinarSeries?->slug) {
                app(FlushWebinarCachesAction::class)->handle(
                    seriesSlug: $variant->webinarSeries->slug,
                );
            }
        });
    }

    public function webinarSeries(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class);
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class, 'webinar_series_variant_id');
    }

    public function waitlistSignups(): HasMany
    {
        return $this->hasMany(
            WebinarWaitlistSignup::class,
            'webinar_series_variant_id',
        );
    }

    public function providerKey(): string
    {
        $provider = is_string($this->platform)
            ? strtolower(trim($this->platform))
            : '';

        return $provider !== ''
            ? $provider
            : $this->webinarSeries?->providerKey()
                ?? static::configuredProviderKey();
    }

    public function providerEventTypeKey(): string
    {
        return WebinarProviderEventType::normalize(
            $this->provider_event_type
                ?? $this->webinarSeries?->providerEventTypeKey()
                ?? config('webinars.provider_event_type'),
        );
    }

    public function providerMatchTitle(): string
    {
        $title = is_string($this->provider_match_title)
            ? trim($this->provider_match_title)
            : '';

        return $title !== ''
            ? $title
            : trim((string) ($this->webinarSeries?->title ?? $this->name));
    }

    public function publicSlug(): string
    {
        return trim((string) $this->public_slug);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : $this->timezone;
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->webinarSeries?->status === 'active';
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