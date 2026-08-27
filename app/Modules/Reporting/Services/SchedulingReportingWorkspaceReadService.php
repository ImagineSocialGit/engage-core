<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SchedulingReportingWorkspaceReadService
{
    /** @return array<string, mixed> */
    public function publicBooking(int $days): array
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
            ->where('metric_key', 'like', 'scheduling.%')
            ->orderBy('metric_date')
            ->get();

        $funnel = $this->funnel($rows);

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
                'observed_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.landing_sessions',
                    ['slice' => 'all'],
                ),
                'likely_human_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.landing_sessions',
                    ['slice' => 'all', 'traffic_class' => 'likely_human'],
                ),
                'public_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.public_appointments',
                    ['slice' => 'all'],
                ),
                'booking_conversion' => $this->ratio(
                    $rows,
                    'scheduling.booking_conversion',
                    ['slice' => 'all', 'traffic_class' => 'likely_human'],
                ),
                'validation_failure_rate' => $this->ratio(
                    $rows,
                    'scheduling.validation_failure_rate',
                    ['slice' => 'all', 'traffic_class' => 'likely_human'],
                ),
                'correlation_coverage' => $this->ratio(
                    $rows,
                    'scheduling.booking_correlation_coverage',
                    ['slice' => 'all'],
                ),
                'attributed_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.attributed_appointments',
                    ['slice' => 'all'],
                ),
                'meta_click_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.booking_attribution_evidence',
                    ['slice' => 'all', 'evidence' => 'meta_click_id'],
                ),
            ],
            'largest_drop' => $this->largestDrop($funnel),
            'funnel' => $funnel,
            'validation_fields' => $this->dimensionCountBreakdown(
                $rows,
                'scheduling.validation_failures',
                'field_key',
            ),
            'availability_outcomes' => $this->dimensionCountBreakdown(
                $rows,
                'scheduling.availability_outcomes',
                'outcome',
            ),
            'verification_channels' => $this->verificationBreakdown($rows),
            'appointment_outcomes' => $this->dimensionCountBreakdown(
                $rows,
                'scheduling.appointment_outcomes',
                'outcome',
            ),
            'campaigns' => $this->browserBreakdown(
                rows: $rows,
                slice: 'campaign',
                identityKeys: [
                    'utm_source',
                    'utm_medium',
                    'referrer_host',
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
            'services' => $this->serviceBreakdown($rows),
            'ad_platform_comparisons' => $this->adPlatformComparisons($from, $through),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function funnel(Collection $rows): array
    {
        $stages = [
            ['key' => 'catalog', 'label' => 'Viewed booking page'],
            ['key' => 'service_selected', 'label' => 'Selected a service'],
            ['key' => 'availability_viewed', 'label' => 'Viewed availability'],
            ['key' => 'time_selected', 'label' => 'Selected a time'],
            ['key' => 'details_started', 'label' => 'Started contact details'],
            ['key' => 'submit_attempt', 'label' => 'Submitted booking'],
        ];
        $result = [];
        $landingCount = 0;
        $previousCount = null;

        foreach ($stages as $stage) {
            $count = $this->countMetric(
                $rows,
                'scheduling.funnel_sessions',
                [
                    'slice' => 'all',
                    'traffic_class' => 'likely_human',
                    'step' => $stage['key'],
                ],
            );

            if ($stage['key'] === 'catalog') {
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
            'scheduling.booking_conversion',
            ['slice' => 'all', 'traffic_class' => 'likely_human'],
        );
        $booked = (int) $conversion['numerator'];

        $result[] = [
            'key' => 'booked',
            'label' => 'Booked appointment',
            'count' => $booked,
            'from_landing_percent' => $landingCount > 0
                ? round(($booked / $landingCount) * 100, 1)
                : null,
            'from_previous_percent' => $previousCount !== null && $previousCount > 0
                ? round(($booked / $previousCount) * 100, 1)
                : null,
        ];

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function largestDrop(array $funnel): ?array
    {
        $largest = null;

        for ($index = 1; $index < count($funnel); $index++) {
            $previous = $funnel[$index - 1];
            $current = $funnel[$index];
            $loss = max(0, (int) $previous['count'] - (int) $current['count']);

            if ((int) $previous['count'] < 1
                || ($largest !== null && $loss <= $largest['lost_sessions'])
            ) {
                continue;
            }

            $largest = [
                'from' => $previous['label'],
                'to' => $current['label'],
                'lost_sessions' => $loss,
                'loss_percent' => round(($loss / (int) $previous['count']) * 100, 1),
            ];
        }

        return $largest;
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
        $result = [];

        foreach ($this->sliceGroups($rows, $slice, $identityKeys) as $dimensions) {
            $human = [...$dimensions, 'traffic_class' => 'likely_human'];
            $result[] = [
                'dimensions' => $dimensions,
                'observed_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.landing_sessions',
                    $dimensions,
                ),
                'likely_human_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.landing_sessions',
                    $human,
                ),
                'time_selected_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.funnel_sessions',
                    [...$human, 'step' => 'time_selected'],
                ),
                'submit_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.funnel_sessions',
                    [...$human, 'step' => 'submit_attempt'],
                ),
                'attributed_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.attributed_appointments',
                    $dimensions,
                ),
                'meta_click_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.booking_attribution_evidence',
                    [...$dimensions, 'evidence' => 'meta_click_id'],
                ),
                'booking_conversion' => $this->ratio(
                    $rows,
                    'scheduling.booking_conversion',
                    $human,
                ),
                'validation_failure_rate' => $this->ratio(
                    $rows,
                    'scheduling.validation_failure_rate',
                    $human,
                ),
            ];
        }

        usort($result, fn (array $a, array $b): int =>
            $b['observed_sessions'] <=> $a['observed_sessions']
        );

        return array_slice($result, 0, 12);
    }

    /** @return array<int, array<string, mixed>> */
    private function serviceBreakdown(Collection $rows): array
    {
        $result = [];

        foreach ($this->sliceGroups($rows, 'service', ['service_key']) as $dimensions) {
            $human = [...$dimensions, 'traffic_class' => 'likely_human'];
            $result[] = [
                'dimensions' => $dimensions,
                'likely_human_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.landing_sessions',
                    $human,
                ),
                'time_selected_sessions' => $this->countMetric(
                    $rows,
                    'scheduling.funnel_sessions',
                    [...$human, 'step' => 'time_selected'],
                ),
                'public_appointments' => $this->countMetric(
                    $rows,
                    'scheduling.public_appointments',
                    $dimensions,
                ),
                'booking_conversion' => $this->ratio(
                    $rows,
                    'scheduling.booking_conversion',
                    $human,
                ),
                'validation_failure_rate' => $this->ratio(
                    $rows,
                    'scheduling.validation_failure_rate',
                    $human,
                ),
            ];
        }

        usort($result, fn (array $a, array $b): int =>
            $b['public_appointments'] <=> $a['public_appointments']
        );

        return array_slice($result, 0, 12);
    }

    /** @return array<int, array<string, mixed>> */
    private function verificationBreakdown(Collection $rows): array
    {
        $groups = [];

        foreach ($this->metricRows(
            $rows,
            'scheduling.verification_channels',
            ['slice' => 'all'],
        ) as $row) {
            $dimensions = is_array($row->dimensions) ? $row->dimensions : [];
            $stage = $dimensions['stage'] ?? null;
            $channel = $dimensions['channel'] ?? null;

            if (! is_string($stage) || ! is_string($channel)) {
                continue;
            }

            $key = $stage.'|'.$channel;
            $groups[$key] = [
                'stage' => $stage,
                'channel' => $channel,
                'count' => ($groups[$key]['count'] ?? 0) + (int) $row->numerator,
            ];
        }

        usort($groups, fn (array $a, array $b): int =>
            [$a['stage'], $a['channel']] <=> [$b['stage'], $b['channel']]
        );

        return array_values($groups);
    }

    /** @return array<int, array<string, mixed>> */
    private function adPlatformComparisons(
        CarbonImmutable $from,
        CarbonImmutable $through,
    ): array {
        $externalRows = ReportingExternalMeasurement::query()
            ->whereDate('period_start', '<=', $through->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->orderByDesc('period_end')
            ->orderBy('platform')
            ->get();
        $comparisons = [];

        foreach ($externalRows->groupBy(function (ReportingExternalMeasurement $row): string {
            return implode('|', [
                $row->platform,
                $row->account_id ?? '',
                $row->period_start?->toDateString() ?? '',
                $row->period_end?->toDateString() ?? '',
                $row->currency ?? '',
                $row->source_file_hash ?? 'direct',
            ]);
        }) as $group) {
            /** @var ReportingExternalMeasurement $first */
            $first = $group->first();
            $periodStart = CarbonImmutable::instance($first->period_start)->startOfDay();
            $periodEnd = CarbonImmutable::instance($first->period_end)->startOfDay();
            $periodRows = ReportingDailyMetric::query()
                ->where('metric_version', ProjectReportingDailyMetricsAction::METRIC_VERSION)
                ->whereBetween('metric_date', [
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                ])
                ->where('metric_key', 'like', 'scheduling.%')
                ->get();
            $stableRows = $group
                ->where('identity_quality', ReportingExternalMeasurement::IDENTITY_STABLE_IDS)
                ->values();
            $matchedRows = 0;
            $observedSessions = 0;
            $humanSessions = 0;
            $appointments = 0;
            $matchedSpend = 0.0;
            $spendAvailable = false;

            foreach ($stableRows as $measurement) {
                $matching = $this->matchingCampaignRows($periodRows, $measurement);

                if ($matching->isEmpty()) {
                    continue;
                }

                $matchedRows++;
                $observedSessions += $this->countMetric(
                    $matching,
                    'scheduling.landing_sessions',
                    [],
                );
                $humanSessions += $this->countMetric(
                    $matching,
                    'scheduling.landing_sessions',
                    ['traffic_class' => 'likely_human'],
                );
                $appointments += $this->countMetric(
                    $matching,
                    'scheduling.attributed_appointments',
                    [],
                );

                if ($measurement->spend !== null) {
                    $matchedSpend += (float) $measurement->spend;
                    $spendAvailable = true;
                }
            }

            $comparisons[] = [
                'platform' => $first->platform,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $first->currency,
                'row_count' => $group->count(),
                'stable_id_rows' => $stableRows->count(),
                'name_fallback_rows' => $group->where(
                    'identity_quality',
                    ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK,
                )->count(),
                'matched_stable_rows' => $matchedRows,
                'external' => [
                    'spend' => $this->nullableNumericSum($group, 'spend'),
                    'impressions' => $this->nullableIntegerSum($group, 'impressions'),
                    'link_clicks' => $this->nullableIntegerSum($group, 'link_clicks'),
                    'landing_page_views' => $this->nullableIntegerSum($group, 'landing_page_views'),
                ],
                'exact_comparison' => [
                    'available' => $matchedRows > 0,
                    'engage_observed_sessions' => $observedSessions,
                    'engage_likely_human_sessions' => $humanSessions,
                    'engage_appointments' => $appointments,
                    'cost_per_appointment' => $spendAvailable && $appointments > 0
                        ? round($matchedSpend / $appointments, 2)
                        : null,
                ],
            ];
        }

        return $comparisons;
    }

    private function matchingCampaignRows(
        Collection $rows,
        ReportingExternalMeasurement $measurement,
    ): Collection {
        return $rows
            ->filter(function (ReportingDailyMetric $row) use ($measurement): bool {
                $dimensions = is_array($row->dimensions) ? $row->dimensions : [];

                if (($dimensions['slice'] ?? null) !== 'campaign'
                    || ($dimensions['external_platform'] ?? null) !== $measurement->platform
                ) {
                    return false;
                }

                foreach ([
                    'external_campaign_id' => $measurement->campaign_id,
                    'external_group_id' => $measurement->group_id,
                    'external_creative_id' => $measurement->creative_id,
                    'external_placement' => $measurement->placement,
                ] as $key => $value) {
                    if ($value !== null && ($dimensions[$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

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

    /** @return array{available: bool, numerator: int, denominator: int, percent: ?float} */
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

    /** @return array<int, array<string, scalar|null>> */
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
            $groupKey = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $groups[$groupKey] = $identity;
        }

        return array_values($groups);
    }

    private function nullableIntegerSum(Collection $rows, string $column): ?int
    {
        $available = $rows->filter(
            fn (ReportingExternalMeasurement $row): bool => $row->{$column} !== null,
        );

        return $available->isEmpty()
            ? null
            : (int) $available->sum($column);
    }

    private function nullableNumericSum(Collection $rows, string $column): ?float
    {
        $available = $rows->filter(
            fn (ReportingExternalMeasurement $row): bool => $row->{$column} !== null,
        );

        return $available->isEmpty()
            ? null
            : round((float) $available->sum(
                fn (ReportingExternalMeasurement $row): float => (float) $row->{$column},
            ), 4);
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
}