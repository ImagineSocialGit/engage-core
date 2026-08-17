<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ReportingWorkspaceReadService
{
    /**
     * @return array<string, mixed>
     */
    public function webinarRegistration(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $timezone = $this->reportingTimezone();
        $through = CarbonImmutable::now($timezone)->startOfDay();
        $from = $through->subDays($days - 1);

        $rows = ReportingDailyMetric::query()
            ->where('metric_version', ProjectReportingDailyMetricsAction::METRIC_VERSION)
            ->whereBetween('metric_date', [
                $from->toDateString(),
                $through->toDateString(),
            ])
            ->where('metric_key', 'like', 'webinar.%')
            ->orderBy('metric_date')
            ->get();

        $traffic = $this->trafficSummary($rows);
        $funnel = $this->funnel($rows);
        $registrationConversion = $this->ratio(
            $rows,
            'webinar.registration_conversion',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
        );
        $validationFailure = $this->ratio(
            $rows,
            'webinar.validation_failure_rate',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
        );
        $correlationCoverage = $this->ratio(
            $rows,
            'webinar.registration_correlation_coverage',
            ['slice' => 'all'],
        );
        $providerCompletion = $this->ratio(
            $rows,
            'webinar.provider_completion',
            ['slice' => 'all'],
        );

        return [
            'has_data' => $rows->isNotEmpty(),
            'timezone' => $timezone,
            'range' => [
                'days' => $days,
                'from' => $from,
                'through' => $through,
            ],
            'updated_at' => $this->latestProjectionTime($rows),
            'summary' => [
                'likely_human_sessions' => $traffic['likely_human']['count'],
                'human_share' => $traffic['likely_human']['share'],
                'registration_conversion' => $registrationConversion,
                'validation_failure_rate' => $validationFailure,
                'correlation_coverage' => $correlationCoverage,
            ],
            'insights' => $this->insights(
                rows: $rows,
                traffic: $traffic,
                funnel: $funnel,
                validationFailure: $validationFailure,
                correlationCoverage: $correlationCoverage,
                providerCompletion: $providerCompletion,
            ),
            'funnel' => $funnel,
            'behavior' => [
                'cta_sessions' => $this->funnelStepCount($rows, 'cta_click'),
                'registration_open_sessions' => $this->funnelStepCount($rows, 'modal_open'),
            ],
            'validation_fields' => $this->dimensionCountBreakdown(
                $rows,
                'webinar.validation_failures',
                'field_key',
            ),
            'traffic' => $traffic,
            'campaigns' => $this->browserBreakdown(
                rows: $rows,
                slice: 'campaign',
                identityKeys: [
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
                ],
            ),
            'paths' => $this->browserBreakdown(
                rows: $rows,
                slice: 'path',
                identityKeys: ['path'],
            ),
            'presentations' => $this->browserBreakdown(
                rows: $rows,
                slice: 'presentation',
                identityKeys: ['page_revision', 'presentation'],
            ),
            'devices' => $this->browserBreakdown(
                rows: $rows,
                slice: 'device',
                identityKeys: ['device_class'],
            ),
            'after_registration' => [
                'local_registrations' => $this->countMetric(
                    $rows,
                    'webinar.local_registrations',
                    ['slice' => 'all'],
                ),
                'correlation_coverage' => $correlationCoverage,
                'provider_completion' => $providerCompletion,
                'confirmation_planning' => $this->ratio(
                    $rows,
                    'webinar.confirmation_planning',
                    ['slice' => 'all'],
                ),
                'confirmation_delivery' => $this->ratio(
                    $rows,
                    'webinar.confirmation_delivery',
                    ['slice' => 'all'],
                ),
                'join_rate' => $this->ratio(
                    $rows,
                    'webinar.join_rate',
                    ['slice' => 'all'],
                ),
                'attendance_rate' => $this->ratio(
                    $rows,
                    'webinar.attendance_rate',
                    ['slice' => 'all'],
                ),
            ],
            'finalization_outcomes' => $this->outcomeBreakdown(
                $rows,
                'webinar.registration_finalization_outcomes',
                includeReason: true,
            ),
            'provider_outcomes' => $this->outcomeBreakdown(
                $rows,
                'webinar.provider_outcomes',
            ),
            'confirmation_outcomes' => $this->outcomeBreakdown(
                $rows,
                'webinar.confirmation_terminal_outcomes',
            ),
            'attendance_outcomes' => $this->outcomeBreakdown(
                $rows,
                'webinar.attendance_outcomes',
            ),
            'series' => $this->producerBreakdown(
                rows: $rows,
                slice: 'series',
                identityKeys: ['series_id', 'series_slug'],
            ),
            'occurrences' => $this->producerBreakdown(
                rows: $rows,
                slice: 'occurrence',
                identityKeys: [
                    'series_id',
                    'series_slug',
                    'occurrence_id',
                    'occurrence_slug',
                ],
            ),
            'diagnostics' => [
                'throttled_requests' => $this->dimensionCountBreakdown(
                    $rows,
                    'webinar.throttled_requests',
                    'reason',
                ),
                'bot_protection_results' => $this->dimensionCountBreakdown(
                    $rows,
                    'webinar.bot_protection_results',
                    'outcome',
                ),
            ],
        ];
    }

    /**
     * @return array<string, array{count: int, share: array<string, mixed>}>
     */
    private function trafficSummary(Collection $rows): array
    {
        $summary = [];

        foreach (['likely_human', 'likely_automated', 'unknown'] as $trafficClass) {
            $dimensions = [
                'slice' => 'all',
                'traffic_class' => $trafficClass,
            ];

            $summary[$trafficClass] = [
                'count' => $this->countMetric(
                    $rows,
                    'webinar.landing_sessions',
                    $dimensions,
                ),
                'share' => $this->ratio(
                    $rows,
                    'webinar.traffic_share',
                    $dimensions,
                ),
            ];
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function funnel(Collection $rows): array
    {
        $stages = [
            ['key' => 'landing', 'label' => 'Landing visits'],
            ['key' => 'form_start', 'label' => 'Form started'],
            ['key' => 'submit_attempt', 'label' => 'Reached submit'],
        ];

        $result = [];
        $landingCount = 0;
        $previousCount = null;

        foreach ($stages as $stage) {
            $count = $this->funnelStepCount($rows, $stage['key']);

            if ($stage['key'] === 'landing') {
                $landingCount = $count;
            }

            $result[] = [
                ...$stage,
                'count' => $count,
                'from_landing_percent' => $landingCount > 0
                    ? round(($count / $landingCount) * 100, 1)
                    : null,
                'from_previous_percent' => $previousCount !== null && $previousCount > 0
                    ? round(($count / $previousCount) * 100, 1)
                    : null,
            ];

            $previousCount = $count;
        }

        $conversion = $this->ratio(
            $rows,
            'webinar.registration_conversion',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
        );
        $registered = (int) $conversion['numerator'];

        $result[] = [
            'key' => 'registered',
            'label' => 'Registered',
            'count' => $registered,
            'from_landing_percent' => $landingCount > 0
                ? round(($registered / $landingCount) * 100, 1)
                : null,
            'from_previous_percent' => $previousCount !== null && $previousCount > 0
                ? round(($registered / $previousCount) * 100, 1)
                : null,
        ];

        return $result;
    }

    private function funnelStepCount(Collection $rows, string $step): int
    {
        return $this->countMetric(
            $rows,
            'webinar.funnel_sessions',
            [
                'slice' => 'all',
                'traffic_class' => 'likely_human',
                'step' => $step,
            ],
        );
    }

    /**
     * @param array<string, array{count: int, share: array<string, mixed>}> $traffic
     * @param array<int, array<string, mixed>> $funnel
     * @param array<string, mixed> $validationFailure
     * @param array<string, mixed> $correlationCoverage
     * @param array<string, mixed> $providerCompletion
     * @return array<int, array{title: string, body: string, tone: string}>
     */
    private function insights(
        Collection $rows,
        array $traffic,
        array $funnel,
        array $validationFailure,
        array $correlationCoverage,
        array $providerCompletion,
    ): array {
        if ($rows->isEmpty()) {
            return [[
                'title' => 'No projected data in this period yet',
                'body' => 'The report will fill in as tracked Webinar registration traffic and authoritative registration outcomes are projected.',
                'tone' => 'neutral',
            ]];
        }

        $insights = [];
        $humanShare = $traffic['likely_human']['share'];

        if ($humanShare['percent'] !== null) {
            $insights[] = [
                'title' => 'Traffic quality',
                'body' => sprintf(
                    '%s%% of observed landing traffic is classified as likely human; %s%% is likely automated and %s%% remains unknown.',
                    $this->formatPercent($humanShare['percent']),
                    $this->formatPercent($traffic['likely_automated']['share']['percent']),
                    $this->formatPercent($traffic['unknown']['share']['percent']),
                ),
                'tone' => 'neutral',
            ];
        }

        $largestDrop = null;

        for ($index = 1; $index < count($funnel); $index++) {
            $previous = $funnel[$index - 1];
            $current = $funnel[$index];
            $previousCount = (int) $previous['count'];
            $currentCount = (int) $current['count'];

            if ($previousCount < 1 || $currentCount >= $previousCount) {
                continue;
            }

            $drop = $previousCount - $currentCount;
            $dropPercent = round(($drop / $previousCount) * 100, 1);

            if ($largestDrop === null || $dropPercent > $largestDrop['percent']) {
                $largestDrop = [
                    'from' => $previous['label'],
                    'to' => $current['label'],
                    'drop' => $drop,
                    'previous' => $previousCount,
                    'percent' => $dropPercent,
                ];
            }
        }

        if ($largestDrop !== null) {
            $insights[] = [
                'title' => 'Largest observed funnel drop',
                'body' => sprintf(
                    '%s → %s: %s of %s sessions did not reach the next measured stage (%s%%).',
                    $largestDrop['from'],
                    $largestDrop['to'],
                    number_format($largestDrop['drop']),
                    number_format($largestDrop['previous']),
                    $this->formatPercent($largestDrop['percent']),
                ),
                'tone' => 'neutral',
            ];
        }

        if ((int) $validationFailure['denominator'] > 0) {
            $insights[] = [
                'title' => 'Validation friction',
                'body' => sprintf(
                    '%s validation failure events were recorded across %s submit attempts (%s%%).',
                    number_format((int) $validationFailure['numerator']),
                    number_format((int) $validationFailure['denominator']),
                    $this->formatPercent($validationFailure['percent']),
                ),
                'tone' => (int) $validationFailure['numerator'] > 0
                    ? 'attention'
                    : 'positive',
            ];
        }

        if ((int) $providerCompletion['denominator'] > 0
            && (int) $providerCompletion['numerator'] < (int) $providerCompletion['denominator']
        ) {
            $failed = (int) $providerCompletion['denominator']
                - (int) $providerCompletion['numerator'];

            $insights[] = [
                'title' => 'Provider completion',
                'body' => sprintf(
                    '%s of %s provider-required registrations did not complete provider sync in this period.',
                    number_format($failed),
                    number_format((int) $providerCompletion['denominator']),
                ),
                'tone' => 'attention',
            ];
        }

        if ((int) $correlationCoverage['denominator'] > 0
            && (int) $correlationCoverage['numerator'] < (int) $correlationCoverage['denominator']
        ) {
            $insights[] = [
                'title' => 'Measurement coverage',
                'body' => sprintf(
                    '%s%% of public registrations could be correlated to a browser submit attempt. Treat browser-to-registration conversion as incomplete coverage for this period.',
                    $this->formatPercent($correlationCoverage['percent']),
                ),
                'tone' => 'attention',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    /**
     * @param array<int, string> $identityKeys
     * @return array<int, array<string, mixed>>
     */
    private function browserBreakdown(
        Collection $rows,
        string $slice,
        array $identityKeys,
    ): array {
        $groups = $this->sliceGroups($rows, $slice, $identityKeys);
        $result = [];

        foreach ($groups as $dimensions) {
            $humanDimensions = [
                ...$dimensions,
                'traffic_class' => 'likely_human',
            ];

            $result[] = [
                'dimensions' => $dimensions,
                'landing_sessions' => $this->countMetric(
                    $rows,
                    'webinar.landing_sessions',
                    $dimensions,
                ),
                'likely_human_sessions' => $this->countMetric(
                    $rows,
                    'webinar.landing_sessions',
                    $humanDimensions,
                ),
                'human_share' => $this->ratio(
                    $rows,
                    'webinar.traffic_share',
                    $humanDimensions,
                ),
                'form_starts' => $this->countMetric(
                    $rows,
                    'webinar.funnel_sessions',
                    [...$humanDimensions, 'step' => 'form_start'],
                ),
                'submit_sessions' => $this->countMetric(
                    $rows,
                    'webinar.funnel_sessions',
                    [...$humanDimensions, 'step' => 'submit_attempt'],
                ),
                'registration_conversion' => $this->ratio(
                    $rows,
                    'webinar.registration_conversion',
                    $humanDimensions,
                ),
                'validation_failure_rate' => $this->ratio(
                    $rows,
                    'webinar.validation_failure_rate',
                    $humanDimensions,
                ),
            ];
        }

        usort($result, fn (array $a, array $b): int =>
            $b['landing_sessions'] <=> $a['landing_sessions']
        );

        return array_slice($result, 0, 12);
    }

    /**
     * @param array<int, string> $identityKeys
     * @return array<int, array<string, mixed>>
     */
    private function producerBreakdown(
        Collection $rows,
        string $slice,
        array $identityKeys,
    ): array {
        $groups = $this->sliceGroups($rows, $slice, $identityKeys);
        $result = [];

        foreach ($groups as $dimensions) {
            $registrations = $this->countMetric(
                $rows,
                'webinar.local_registrations',
                $dimensions,
            );

            if ($registrations < 1) {
                continue;
            }

            $result[] = [
                'dimensions' => $dimensions,
                'local_registrations' => $registrations,
                'provider_completion' => $this->ratio(
                    $rows,
                    'webinar.provider_completion',
                    $dimensions,
                ),
                'confirmation_delivery' => $this->ratio(
                    $rows,
                    'webinar.confirmation_delivery',
                    $dimensions,
                ),
                'join_rate' => $this->ratio(
                    $rows,
                    'webinar.join_rate',
                    $dimensions,
                ),
                'attendance_rate' => $this->ratio(
                    $rows,
                    'webinar.attendance_rate',
                    $dimensions,
                ),
            ];
        }

        usort($result, fn (array $a, array $b): int =>
            $b['local_registrations'] <=> $a['local_registrations']
        );

        return array_slice($result, 0, 12);
    }

    /**
     * @param array<int, string> $identityKeys
     * @return array<int, array<string, scalar|null>>
     */
    private function sliceGroups(
        Collection $rows,
        string $slice,
        array $identityKeys,
    ): array {
        $groups = [];

        foreach ($rows as $row) {
            $dimensions = is_array($row->dimensions) ? $row->dimensions : [];

            if (($dimensions['slice'] ?? null) !== $slice) {
                continue;
            }

            $identity = ['slice' => $slice];
            $hasIdentity = false;

            foreach ($identityKeys as $key) {
                $value = $dimensions[$key] ?? null;
                $identity[$key] = is_scalar($value) ? $value : null;
                $hasIdentity = $hasIdentity || ($value !== null && $value !== '');
            }

            if (! $hasIdentity) {
                continue;
            }

            $canonical = $identity;
            ksort($canonical);
            $groupKey = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $groups[$groupKey] = $identity;
        }

        return array_values($groups);
    }

    /**
     * @return array<int, array{outcome: string, reason: ?string, count: int}>
     */
    private function outcomeBreakdown(
        Collection $rows,
        string $metricKey,
        bool $includeReason = false,
    ): array {
        $groups = [];

        foreach ($this->metricRows($rows, $metricKey, ['slice' => 'all']) as $row) {
            $dimensions = is_array($row->dimensions) ? $row->dimensions : [];
            $outcome = $dimensions['outcome'] ?? null;

            if (! is_string($outcome) || trim($outcome) === '') {
                continue;
            }

            $reason = $includeReason && is_string($dimensions['reason'] ?? null)
                ? trim((string) $dimensions['reason'])
                : null;
            $key = $outcome.'|'.($reason ?? '');

            $groups[$key] ??= [
                'outcome' => $outcome,
                'reason' => $reason !== '' ? $reason : null,
                'count' => 0,
            ];
            $groups[$key]['count'] += (int) $row->numerator;
        }

        $result = array_values($groups);
        usort($result, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * @return array<int, array{value: string, count: int}>
     */
    private function dimensionCountBreakdown(
        Collection $rows,
        string $metricKey,
        string $dimensionKey,
    ): array {
        $groups = [];

        foreach ($this->metricRows($rows, $metricKey, ['slice' => 'all']) as $row) {
            $dimensions = is_array($row->dimensions) ? $row->dimensions : [];
            $value = $dimensions[$dimensionKey] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            $groups[$value] = ($groups[$value] ?? 0) + (int) $row->numerator;
        }

        arsort($groups);

        return array_map(
            fn (string $value, int $count): array => [
                'value' => $value,
                'count' => $count,
            ],
            array_keys($groups),
            array_values($groups),
        );
    }

    private function countMetric(
        Collection $rows,
        string $metricKey,
        array $dimensions,
    ): int {
        return (int) $this->metricRows($rows, $metricKey, $dimensions)
            ->sum('numerator');
    }

    /**
     * @return array{available: bool, numerator: int, denominator: int, percent: ?float}
     */
    private function ratio(
        Collection $rows,
        string $metricKey,
        array $dimensions,
    ): array {
        $metricRows = $this->metricRows($rows, $metricKey, $dimensions);
        $numerator = (int) $metricRows->sum('numerator');
        $denominator = (int) $metricRows
            ->filter(fn (ReportingDailyMetric $row): bool => $row->denominator !== null)
            ->sum('denominator');

        return [
            'available' => $metricRows->isNotEmpty(),
            'numerator' => $numerator,
            'denominator' => $denominator,
            'percent' => $denominator > 0
                ? round(($numerator / $denominator) * 100, 1)
                : null,
        ];
    }

    private function metricRows(
        Collection $rows,
        string $metricKey,
        array $dimensions,
    ): Collection {
        return $rows
            ->filter(function (ReportingDailyMetric $row) use ($metricKey, $dimensions): bool {
                if ($row->metric_key !== $metricKey) {
                    return false;
                }

                $rowDimensions = is_array($row->dimensions)
                    ? $row->dimensions
                    : [];

                foreach ($dimensions as $key => $value) {
                    if (($rowDimensions[$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function latestProjectionTime(Collection $rows): mixed
    {
        return $rows
            ->filter(fn (ReportingDailyMetric $row): bool => $row->projected_through !== null)
            ->sortByDesc(fn (ReportingDailyMetric $row): int =>
                $row->projected_through?->getTimestamp() ?? 0
            )
            ->first()?->projected_through;
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

    private function formatPercent(?float $value): string
    {
        return number_format($value ?? 0.0, 1);
    }
}