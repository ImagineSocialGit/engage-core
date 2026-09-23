<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ReportingCollectionHealthReadService
{
    private const PROJECTION_STALE_AFTER_SECONDS = 900;

    /**
     * @return array<string, mixed>
     */
    public function read(int $windowHours = 24): array
    {
        $windowHours = min(168, max(1, $windowHours));
        $now = CarbonImmutable::now('UTC');
        $windowStart = $now->subHours($windowHours);

        $recentBrowser = ReportingObservation::query()
            ->where('source', 'browser')
            ->where('received_at', '>=', $windowStart);

        $recentCount = (clone $recentBrowser)->count();
        $latestObservation = ReportingObservation::query()
            ->where('source', 'browser')
            ->orderByDesc('received_at')
            ->first();
        $latestReceivedAt = $latestObservation?->received_at;
        $attributedCount = $this->withAttributionEvidence(
            clone $recentBrowser,
        )->count();
        $surfaces = $this->surfaceActivity(clone $recentBrowser);

        $collection = $this->collectionHealth(
            windowHours: $windowHours,
            recentCount: $recentCount,
            latestReceivedAt: $latestReceivedAt,
            surfaces: $surfaces,
        );
        $attribution = $this->attributionHealth(
            recentCount: $recentCount,
            attributedCount: $attributedCount,
        );
        $projection = $this->projectionHealth(
            latestReceivedAt: $latestReceivedAt,
        );
        $metaPixel = $this->metaPixelHealth();

        return [
            'window_hours' => $windowHours,
            'overall_status' => $this->overallStatus(
                collection: $collection,
                projection: $projection,
                metaPixel: $metaPixel,
            ),
            'collection' => $collection,
            'attribution' => $attribution,
            'projection' => $projection,
            'meta_pixel' => $metaPixel,
            'cards' => [
                $this->collectionCard($collection, $windowHours),
                $this->attributionCard($attribution, $windowHours),
                $this->projectionCard($projection),
                $this->metaPixelCard($metaPixel),
            ],
        ];
    }

    /**
     * @param Collection<int, array{surface: string, observation_count: int, latest_received_at: CarbonImmutable|null}> $surfaces
     * @return array<string, mixed>
     */
    private function collectionHealth(
        int $windowHours,
        int $recentCount,
        ?CarbonImmutable $latestReceivedAt,
        Collection $surfaces,
    ): array {
        $enabled = config('reporting.collection.browser_enabled') === true;
        $status = match (true) {
            ! $enabled => 'disabled',
            $recentCount > 0 => 'collecting',
            $latestReceivedAt instanceof CarbonImmutable => 'no_recent_activity',
            default => 'empty',
        };

        return [
            'enabled' => $enabled,
            'status' => $status,
            'window_hours' => $windowHours,
            'recent_observation_count' => $recentCount,
            'latest_received_at' => $latestReceivedAt,
            'surfaces' => $surfaces->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function attributionHealth(
        int $recentCount,
        int $attributedCount,
    ): array {
        return [
            'status' => match (true) {
                $recentCount === 0 => 'no_recent_data',
                $attributedCount > 0 => 'present',
                default => 'none',
            },
            'recent_observation_count' => $recentCount,
            'with_attribution_count' => $attributedCount,
            'without_attribution_count' => max(
                0,
                $recentCount - $attributedCount,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function projectionHealth(
        ?CarbonImmutable $latestReceivedAt,
    ): array {
        $checkpoint = ReportingProjectionCheckpoint::query()
            ->where(
                'projector_key',
                ProjectReportingDailyMetricsAction::PROJECTOR_KEY,
            )
            ->where(
                'projector_version',
                ProjectReportingDailyMetricsAction::PROJECTOR_VERSION,
            )
            ->first();

        $projectedThrough = $checkpoint?->projected_through;
        $lagSeconds = 0;

        if ($latestReceivedAt instanceof CarbonImmutable
            && $projectedThrough instanceof CarbonImmutable
        ) {
            $lagSeconds = max(
                0,
                $latestReceivedAt->getTimestamp()
                    - $projectedThrough->getTimestamp(),
            );
        }

        $status = match (true) {
            ! $checkpoint instanceof ReportingProjectionCheckpoint => 'missing',
            $lagSeconds >= self::PROJECTION_STALE_AFTER_SECONDS => 'behind',
            default => 'current',
        };

        return [
            'status' => $status,
            'projector_key' => ProjectReportingDailyMetricsAction::PROJECTOR_KEY,
            'projector_version' => ProjectReportingDailyMetricsAction::PROJECTOR_VERSION,
            'projected_through' => $projectedThrough,
            'latest_observation_received_at' => $latestReceivedAt,
            'lag_seconds' => $lagSeconds,
            'lag_minutes' => (int) floor($lagSeconds / 60),
            'window_start' => $checkpoint?->window_start,
            'window_end' => $checkpoint?->window_end,
            'metrics_written' => (int) data_get($checkpoint?->meta, 'metrics', 0),
        ];
    }

    /** @return array<string, mixed> */
    private function metaPixelHealth(): array
    {
        $config = config('public_surfaces.tracking.meta_pixel', []);
        $config = is_array($config) ? $config : [];
        $enabled = ($config['enabled'] ?? false) === true;
        $pixelId = is_string($config['pixel_id'] ?? null)
            ? trim((string) $config['pixel_id'])
            : '';
        $events = is_array($config['events'] ?? null)
            ? $config['events']
            : [];
        $mappedEvents = collect($events)
            ->filter(
                fn (mixed $eventName, mixed $eventKey): bool => is_string($eventKey)
                    && trim($eventKey) !== ''
                    && is_string($eventName)
                    && trim($eventName) !== '',
            )
            ->mapWithKeys(
                fn (string $eventName, string $eventKey): array => [
                    trim($eventKey) => trim($eventName),
                ],
            )
            ->all();
        $configured = $enabled && $pixelId !== '';

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'status' => match (true) {
                ! $enabled => 'disabled',
                $configured => 'configured',
                default => 'incomplete',
            },
            'pixel_id_present' => $pixelId !== '',
            'conversion_event_count' => count($mappedEvents),
            'conversion_events' => array_keys($mappedEvents),
        ];
    }

    private function withAttributionEvidence(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach ([
                'referrer_host',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_content',
                'utm_term',
                'external_platform',
                'external_campaign_id',
                'external_group_id',
                'external_creative_id',
                'external_placement',
                'click_id_hashes',
            ] as $column) {
                $query->orWhereNotNull($column);
            }
        });
    }

    /**
     * @return Collection<int, array{surface: string, observation_count: int, latest_received_at: CarbonImmutable|null}>
     */
    private function surfaceActivity(Builder $query): Collection
    {
        return $query
            ->select('surface')
            ->selectRaw('COUNT(*) AS observation_count')
            ->selectRaw('MAX(received_at) AS latest_received_at')
            ->groupBy('surface')
            ->orderByDesc('observation_count')
            ->get()
            ->map(function (ReportingObservation $row): array {
                $latest = $row->getAttribute('latest_received_at');

                return [
                    'surface' => (string) $row->surface,
                    'observation_count' => (int) $row->getAttribute('observation_count'),
                    'latest_received_at' => is_string($latest)
                        && trim($latest) !== ''
                            ? CarbonImmutable::parse($latest, 'UTC')
                            : null,
                ];
            });
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $projection
     * @param array<string, mixed> $metaPixel
     */
    private function overallStatus(
        array $collection,
        array $projection,
        array $metaPixel,
    ): string {
        if (($collection['status'] ?? null) === 'disabled'
            || ($projection['status'] ?? null) === 'behind'
            || (($projection['status'] ?? null) === 'missing'
                && (int) ($collection['recent_observation_count'] ?? 0) > 0)
            || ($metaPixel['status'] ?? null) === 'incomplete'
        ) {
            return 'attention';
        }

        if (($collection['status'] ?? null) === 'collecting'
            && ($projection['status'] ?? null) === 'current'
        ) {
            return 'positive';
        }

        return 'neutral';
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, string>
     */
    private function collectionCard(array $collection, int $windowHours): array
    {
        $status = (string) ($collection['status'] ?? 'empty');
        $recentCount = (int) ($collection['recent_observation_count'] ?? 0);
        $latest = $collection['latest_received_at'] ?? null;
        $surfaces = collect($collection['surfaces'] ?? [])
            ->take(3)
            ->map(function (mixed $surface): ?string {
                if (! is_array($surface)) {
                    return null;
                }

                $key = is_string($surface['surface'] ?? null)
                    ? trim((string) $surface['surface'])
                    : '';

                if ($key === '') {
                    return null;
                }

                return Str::headline($key).' '.number_format(
                    (int) ($surface['observation_count'] ?? 0),
                );
            })
            ->filter()
            ->implode(' · ');

        $value = match ($status) {
            'disabled' => 'Off',
            'collecting' => number_format($recentCount).' received',
            'no_recent_activity' => 'No recent activity',
            default => 'No activity yet',
        };
        $detail = match ($status) {
            'disabled' => 'Browser observation collection is disabled in Reporting configuration.',
            'collecting' => sprintf(
                'Last %d hours%s.',
                $windowHours,
                $surfaces !== '' ? ': '.$surfaces : '',
            ),
            'no_recent_activity' => 'The endpoint has received browser observations before, but none are inside the current health window. Last received '.$this->formatTime($latest).'.',
            default => 'No browser observation has been stored yet. This can mean there has been no tracked traffic or the browser transport has not reached the server.',
        };

        return [
            'key' => 'collection',
            'label' => 'Browser collection',
            'value' => $value,
            'detail' => $detail,
            'tone' => $status === 'disabled'
                ? 'attention'
                : ($status === 'collecting' ? 'positive' : 'neutral'),
        ];
    }

    /**
     * @param array<string, mixed> $attribution
     * @return array<string, string>
     */
    private function attributionCard(array $attribution, int $windowHours): array
    {
        $status = (string) ($attribution['status'] ?? 'no_recent_data');
        $total = (int) ($attribution['recent_observation_count'] ?? 0);
        $attributed = (int) ($attribution['with_attribution_count'] ?? 0);

        return [
            'key' => 'attribution',
            'label' => 'Attribution evidence',
            'value' => match ($status) {
                'present' => number_format($attributed).' of '.number_format($total),
                'none' => 'None detected',
                default => 'No recent data',
            },
            'detail' => match ($status) {
                'present' => sprintf(
                    'Browser observations with campaign, referrer, external-ID, or approved click-ID evidence in the last %d hours.',
                    $windowHours,
                ),
                'none' => sprintf(
                    'The last %d hours contain browser observations, but none carry campaign, referrer, external-ID, or approved click-ID evidence. Direct traffic can legitimately look this way.',
                    $windowHours,
                ),
                default => 'Attribution cannot be evaluated until browser observations arrive in the current health window.',
            },
            'tone' => $status === 'present' ? 'positive' : 'neutral',
        ];
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, string>
     */
    private function projectionCard(array $projection): array
    {
        $status = (string) ($projection['status'] ?? 'missing');
        $projectedThrough = $projection['projected_through'] ?? null;
        $lagMinutes = (int) ($projection['lag_minutes'] ?? 0);

        return [
            'key' => 'projection',
            'label' => 'Daily projection',
            'value' => match ($status) {
                'current' => 'Current',
                'behind' => 'Behind collection',
                default => 'Not projected yet',
            },
            'detail' => match ($status) {
                'current' => 'Current projector checkpoint: '.$this->formatTime($projectedThrough).'.',
                'behind' => sprintf(
                    'The newest browser observation is about %d minute(s) newer than the current projector checkpoint. Refresh recent data or verify the scheduler.',
                    $lagMinutes,
                ),
                default => 'No checkpoint exists for the current public-funnel projector yet. It will be created when projection runs.',
            },
            'tone' => $status === 'behind'
                ? 'attention'
                : ($status === 'current' ? 'positive' : 'neutral'),
        ];
    }

    /**
     * @param array<string, mixed> $metaPixel
     * @return array<string, string>
     */
    private function metaPixelCard(array $metaPixel): array
    {
        $status = (string) ($metaPixel['status'] ?? 'disabled');
        $eventCount = (int) ($metaPixel['conversion_event_count'] ?? 0);

        return [
            'key' => 'meta_pixel',
            'label' => 'Meta Pixel',
            'value' => match ($status) {
                'configured' => 'Configured',
                'incomplete' => 'Needs configuration',
                default => 'Off',
            },
            'detail' => match ($status) {
                'configured' => sprintf(
                    'Pixel ID is configured with %d conversion event mapping(s). The server can verify configuration, not whether Meta received a browser event.',
                    $eventCount,
                ),
                'incomplete' => 'Meta Pixel is enabled but no usable Pixel ID is configured.',
                default => 'Meta Pixel is optional and currently disabled for shared public surfaces.',
            },
            'tone' => $status === 'incomplete'
                ? 'attention'
                : ($status === 'configured' ? 'positive' : 'neutral'),
        ];
    }

    private function formatTime(mixed $value): string
    {
        if (! $value instanceof CarbonImmutable) {
            return 'unknown';
        }

        return $value
            ->setTimezone($this->reportingTimezone())
            ->format('M j, Y g:i A T');
    }

    private function reportingTimezone(): string
    {
        $timezone = config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        return is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'UTC';
    }
}