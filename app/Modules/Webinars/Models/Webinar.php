<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus;
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

    public const HIDDEN_REASON_OPERATOR_REMOVED = 'operator_removed';

    protected static function newFactory(): WebinarFactory
    {
        return WebinarFactory::new();
    }

    protected $fillable = [
        'webinar_series_id',
        'webinar_series_variant_id',
        'replacement_of_webinar_id',
        'webinar_schedule_profile_id',
        'title',
        'slug',
        'platform',
        'provider_event_type',
        'external_id',
        'host_account_key',
        'provider_lifecycle_status',
        'provider_missing_at',
        'provider_archived_at',
        'hidden_at',
        'hidden_reason',
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
        'provider_missing_at' => 'datetime',
        'provider_archived_at' => 'datetime',
        'hidden_at' => 'datetime',
        'meta' => 'array',
        'provider_settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Webinar $webinar): void {
            if (blank($webinar->platform)) {
                $webinar->platform = $webinar->webinarSeriesVariant?->providerKey()
                    ?? $webinar->webinarSeries?->providerKey()
                    ?? static::configuredProviderKey();
            }

            $webinar->provider_event_type = WebinarProviderEventType::normalize(
                $webinar->provider_event_type
                    ?? $webinar->webinarSeriesVariant?->providerEventTypeKey()
                    ?? $webinar->webinarSeries?->providerEventTypeKey()
                    ?? config('webinars.provider_event_type'),
            );

            $webinar->provider_lifecycle_status = WebinarProviderLifecycleStatus::normalize(
                $webinar->provider_lifecycle_status,
            );
        });

        static::saved(function (Webinar $webinar): void {
            if (
                ! $webinar->wasRecentlyCreated
                && ! $webinar->wasChanged([
                    'starts_at',
                    'ends_at',
                    'webinar_series_id',
                    'webinar_series_variant_id',
                    'replacement_of_webinar_id',
                    'webinar_schedule_profile_id',
                    'platform',
                    'provider_event_type',
                    'provider_lifecycle_status',
                    'provider_missing_at',
                    'provider_archived_at',
                    'hidden_at',
                    'hidden_reason',
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

    public function webinarSeriesVariant(): BelongsTo
    {
        return $this->belongsTo(
            WebinarSeriesVariant::class,
            'webinar_series_variant_id',
        );
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
            ->matchingCurrentSeriesProvider();
    }

    public function scopeForVariantProviderIdentity(
        Builder $query,
        WebinarSeriesVariant $variant,
    ): Builder {
        return $query
            ->where('webinar_series_id', $variant->webinar_series_id)
            ->where('webinar_series_variant_id', $variant->getKey())
            ->where('platform', $variant->providerKey())
            ->where('provider_event_type', $variant->providerEventTypeKey());
    }

    public function scopeMatchingCurrentSeriesProvider(Builder $query): Builder
    {
        return $query->where(function (Builder $identity): void {
            $identity
                ->where(function (Builder $variantBound): void {
                    $variantBound
                        ->whereNotNull('webinar_series_variant_id')
                        ->whereHas(
                            'webinarSeriesVariant',
                            fn (Builder $variantQuery): Builder => $variantQuery
                                ->where('webinar_series_variants.status', 'active')
                                ->whereColumn('webinar_series_variants.platform', 'webinars.platform')
                                ->whereColumn(
                                    'webinar_series_variants.provider_event_type',
                                    'webinars.provider_event_type',
                                ),
                        );
                })
                ->orWhere(function (Builder $legacy): void {
                    $legacy
                        ->whereNull('webinar_series_variant_id')
                        ->whereHas(
                            'webinarSeries',
                            fn (Builder $seriesQuery): Builder => $seriesQuery
                                ->whereColumn('webinar_series.platform', 'webinars.platform')
                                ->whereColumn(
                                    'webinar_series.provider_event_type',
                                    'webinars.provider_event_type',
                                ),
                        );
                });
        });
    }

    public function scopeProviderActive(Builder $query): Builder
    {
        return $query->where(
            'provider_lifecycle_status',
            WebinarProviderLifecycleStatus::Active->value,
        );
    }

    public function scopeProviderMissing(Builder $query): Builder
    {
        return $query->where(
            'provider_lifecycle_status',
            WebinarProviderLifecycleStatus::Missing->value,
        );
    }

    public function scopeProviderArchived(Builder $query): Builder
    {
        return $query->where(
            'provider_lifecycle_status',
            WebinarProviderLifecycleStatus::Archived->value,
        );
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    public function scopeHidden(Builder $query): Builder
    {
        return $query->whereNotNull('hidden_at');
    }

    public function matchesSeriesProviderIdentity(?WebinarSeries $series = null): bool
    {
        $series ??= $this->relationLoaded('webinarSeries')
            ? $this->getRelation('webinarSeries')
            : $this->webinarSeries()->first();

        if (! $series instanceof WebinarSeries
            || (int) $this->webinar_series_id !== (int) $series->getKey()
        ) {
            return false;
        }

        $variant = $this->relationLoaded('webinarSeriesVariant')
            ? $this->getRelation('webinarSeriesVariant')
            : $this->webinarSeriesVariant()->first();

        if ($variant instanceof WebinarSeriesVariant) {
            return $this->matchesVariantProviderIdentity($variant);
        }

        return $this->providerKey() === $series->providerKey()
            && $this->providerEventTypeKey() === $series->providerEventTypeKey();
    }

    public function matchesVariantProviderIdentity(
        WebinarSeriesVariant $variant,
    ): bool {
        return (int) $this->webinar_series_id === (int) $variant->webinar_series_id
            && (int) $this->webinar_series_variant_id === (int) $variant->getKey()
            && $this->providerKey() === $variant->providerKey()
            && $this->providerEventTypeKey() === $variant->providerEventTypeKey();
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

    public function providerLifecycleStatus(): WebinarProviderLifecycleStatus
    {
        return WebinarProviderLifecycleStatus::from(
            WebinarProviderLifecycleStatus::normalize(
                $this->provider_lifecycle_status,
            ),
        );
    }

    public function isProviderActive(): bool
    {
        return $this->providerLifecycleStatus() === WebinarProviderLifecycleStatus::Active;
    }

    public function isProviderMissing(): bool
    {
        return $this->providerLifecycleStatus() === WebinarProviderLifecycleStatus::Missing;
    }

    public function isProviderArchived(): bool
    {
        return $this->providerLifecycleStatus() === WebinarProviderLifecycleStatus::Archived;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
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