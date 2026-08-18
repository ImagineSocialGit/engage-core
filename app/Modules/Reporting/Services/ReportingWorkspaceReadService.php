<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ReportingWorkspaceReadService
{
    private const COMPARISON_MIN_LIKELY_HUMAN_SESSIONS = 20;

    private const COMPARISON_MIN_GAP_PERCENTAGE_POINTS = 5.0;

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
        $validationFields = $this->dimensionCountBreakdown(
            $rows,
            'webinar.validation_failures',
            'field_key',
        );
        $adPlatformComparisons = $this->adPlatformComparisons($from, $through);
        $performanceComparisons = $this->performanceComparisons($rows);

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
            'decision_summary' => $this->decisionSummary(
                rows: $rows,
                traffic: $traffic,
                funnel: $funnel,
                validationFailure: $validationFailure,
                validationFields: $validationFields,
                correlationCoverage: $correlationCoverage,
                providerCompletion: $providerCompletion,
                adPlatformComparisons: $adPlatformComparisons,
            ),
            'supporting_signals' => $this->insights(
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
            'validation_fields' => $validationFields,
            'traffic' => $traffic,
            'ad_platform_comparisons' => $adPlatformComparisons,
            'performance_comparisons' => $performanceComparisons,
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
     * @param array<int, array{value: string, count: int}> $validationFields
     * @param array<string, mixed> $correlationCoverage
     * @param array<string, mixed> $providerCompletion
     * @param array<int, array<string, mixed>> $adPlatformComparisons
     * @return array<string, array<string, string>>
     */
    private function decisionSummary(
        Collection $rows,
        array $traffic,
        array $funnel,
        array $validationFailure,
        array $validationFields,
        array $correlationCoverage,
        array $providerCompletion,
        array $adPlatformComparisons,
    ): array {
        return [
            'primary' => $this->primaryInvestigation(
                rows: $rows,
                funnel: $funnel,
                validationFields: $validationFields,
                providerCompletion: $providerCompletion,
            ),
            'measurement' => $this->measurementContext(
                traffic: $traffic,
                correlationCoverage: $correlationCoverage,
            ),
            'acquisition' => $this->acquisitionContext($adPlatformComparisons),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $funnel
     * @param array<int, array{value: string, count: int}> $validationFields
     * @param array<string, mixed> $providerCompletion
     * @return array<string, string>
     */
    private function primaryInvestigation(
        Collection $rows,
        array $funnel,
        array $validationFields,
        array $providerCompletion,
    ): array {
        if ($rows->isEmpty()) {
            return [
                'label' => 'First investigation',
                'title' => 'No projected funnel data yet',
                'body' => 'There is not enough projected first-party activity in this period to identify an observed funnel loss.',
                'next_step' => 'Collect tracked landing and registration activity, then refresh recent Reporting data.',
                'tone' => 'neutral',
            ];
        }

        $largestDrop = $this->largestFunnelDrop($funnel);

        if ($largestDrop !== null) {
            return [
                'label' => 'First investigation',
                'title' => sprintf(
                    'Investigate %s → %s first',
                    $largestDrop['from'],
                    $largestDrop['to'],
                ),
                'body' => sprintf(
                    '%s of %s likely-human sessions did not reach the next measured stage (%s%%). This is the largest observed session loss in the primary funnel for this period.',
                    number_format($largestDrop['drop']),
                    number_format($largestDrop['previous']),
                    $this->formatPercent($largestDrop['percent']),
                ),
                'next_step' => $this->funnelInvestigationNextStep(
                    fromKey: $largestDrop['from_key'],
                    toKey: $largestDrop['to_key'],
                    validationFields: $validationFields,
                ),
                'tone' => 'attention',
            ];
        }

        $landing = (int) ($funnel[0]['count'] ?? 0);
        $registeredStage = collect($funnel)->firstWhere('key', 'registered');
        $registered = is_array($registeredStage)
            ? (int) ($registeredStage['count'] ?? 0)
            : 0;
        $providerDenominator = (int) ($providerCompletion['denominator'] ?? 0);
        $providerNumerator = (int) ($providerCompletion['numerator'] ?? 0);

        if ($landing > 0 && $registered >= $landing
            && $providerDenominator > 0
            && $providerNumerator < $providerDenominator
        ) {
            return [
                'label' => 'First investigation',
                'title' => 'Public registration completed; inspect provider completion next',
                'body' => sprintf(
                    'All %s likely-human landing sessions reached a correlated local registration, but %s of %s provider-required registrations did not complete provider sync.',
                    number_format($landing),
                    number_format($providerDenominator - $providerNumerator),
                    number_format($providerDenominator),
                ),
                'next_step' => 'Review provider finalization outcomes before changing landing-page copy, form fields, or acquisition spend.',
                'tone' => 'attention',
            ];
        }

        if ($landing > 0) {
            return [
                'label' => 'First investigation',
                'title' => 'No measured pre-registration loss is visible yet',
                'body' => sprintf(
                    'All %s likely-human landing sessions reached a correlated registration in this period.',
                    number_format($landing),
                ),
                'next_step' => 'Keep collecting likely-human traffic before treating the current conversion result as representative of normal performance.',
                'tone' => 'positive',
            ];
        }

        return [
            'label' => 'First investigation',
            'title' => 'No likely-human funnel is available yet',
            'body' => 'Observed traffic exists, but the primary likely-human registration funnel has no eligible landing sessions in this period.',
            'next_step' => 'Review Traffic quality first, then generate or wait for additional classified browser traffic before judging conversion.',
            'tone' => 'neutral',
        ];
    }

    /**
     * @param array<int, array{value: string, count: int}> $validationFields
     */
    private function funnelInvestigationNextStep(
        string $fromKey,
        string $toKey,
        array $validationFields,
    ): string {
        if ($fromKey === 'landing' && $toKey === 'form_start') {
            return 'Compare device and page/presentation breakdowns, then review registration visibility and the path from landing content into the form before changing ad spend.';
        }

        if ($fromKey === 'form_start' && $toKey === 'submit_attempt') {
            return 'Review form requirements, consent choices, field usability, and the validation breakdown before changing acquisition spend or page messaging.';
        }

        if ($fromKey === 'submit_attempt' && $toKey === 'registered') {
            $field = $validationFields[0]['value'] ?? null;
            $fieldHint = is_string($field) && trim($field) !== ''
                ? ' Start with the '.ucwords(str_replace(['_', '-'], ' ', trim($field))).' validation category.'
                : '';

            return 'Review validation failures, bot-protection outcomes, and registration finalization before changing page copy or ad spend.'.$fieldHint;
        }

        return 'Compare the detailed funnel and breakdown evidence for this transition before changing the public experience or acquisition settings.';
    }

    /**
     * @param array<string, array{count: int, share: array<string, mixed>}> $traffic
     * @param array<string, mixed> $correlationCoverage
     * @return array<string, string>
     */
    private function measurementContext(
        array $traffic,
        array $correlationCoverage,
    ): array {
        $likelyHuman = (int) ($traffic['likely_human']['count'] ?? 0);
        $likelyAutomated = (int) ($traffic['likely_automated']['count'] ?? 0);
        $unknown = (int) ($traffic['unknown']['count'] ?? 0);
        $observed = $likelyHuman + $likelyAutomated + $unknown;
        $correlated = (int) ($correlationCoverage['numerator'] ?? 0);
        $registrations = (int) ($correlationCoverage['denominator'] ?? 0);

        $body = sprintf(
            'The primary funnel uses %s likely-human landing sessions out of %s observed landing sessions.',
            number_format($likelyHuman),
            number_format($observed),
        );

        if ($unknown > 0) {
            $body .= sprintf(
                ' %s observed landing sessions remain unknown and are intentionally excluded from conversion.',
                number_format($unknown),
            );
        }

        if ($registrations > 0) {
            $body .= sprintf(
                ' Browser-to-registration correlation covers %s of %s local registrations.',
                number_format($correlated),
                number_format($registrations),
            );
        }

        $nextStep = 'Use the likely-human funnel for conversion decisions and the broader observed totals only as traffic-quality context.';
        $tone = 'positive';

        if ($registrations > 0 && $correlated < $registrations) {
            $nextStep = 'Treat browser-to-registration conversion as incomplete coverage until correlation improves; authoritative registration totals remain valid.';
            $tone = 'attention';
        } elseif ($unknown > 0) {
            $nextStep = 'Keep the unknown share visible when interpreting conversion; do not silently add those sessions to the likely-human denominator.';
            $tone = 'neutral';
        }

        return [
            'label' => 'Measurement coverage',
            'title' => 'Know which population each number describes',
            'body' => $body,
            'next_step' => $nextStep,
            'tone' => $tone,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $comparisons
     * @return array<string, string>
     */
    private function acquisitionContext(array $comparisons): array
    {
        if ($comparisons === []) {
            return [
                'label' => 'Ad attribution',
                'title' => 'No ad-platform report is attached to this view yet',
                'body' => 'Engage can diagnose the first-party funnel without platform data, but spend and platform-reported traffic are not available for comparison.',
                'next_step' => 'Import the relevant Meta, TikTok, or Google/YouTube report when acquisition cost should be part of the decision.',
                'tone' => 'neutral',
            ];
        }

        $comparison = $comparisons[0];
        $platform = ucwords(str_replace(['_', '-'], ' ', (string) ($comparison['platform'] ?? 'ad platform')));
        $stableRows = (int) ($comparison['stable_id_rows'] ?? 0);
        $fallbackRows = (int) ($comparison['name_fallback_rows'] ?? 0);
        $matchedRows = (int) ($comparison['matched_stable_rows'] ?? 0);
        $rowCount = (int) ($comparison['row_count'] ?? 0);
        $exact = is_array($comparison['exact_comparison'] ?? null)
            ? $comparison['exact_comparison']
            : [];

        if (($exact['available'] ?? false) === true) {
            $body = sprintf(
                'The most recent %s import has exact stable-ID reconciliation for %s of %s stable-ID rows.',
                $platform,
                number_format($matchedRows),
                number_format($stableRows),
            );

            if (($exact['cost_per_registration'] ?? null) !== null) {
                $currency = is_string($comparison['currency'] ?? null)
                    ? trim((string) $comparison['currency'])
                    : '';
                $body .= sprintf(
                    ' Matched spend currently works out to %s%s per correlated Engage registration.',
                    $currency !== '' ? $currency.' ' : '',
                    number_format((float) $exact['cost_per_registration'], 2),
                );
            }

            if ($fallbackRows > 0) {
                $body .= sprintf(
                    ' %s additional imported rows are name-fallback only and are excluded from exact attribution.',
                    number_format($fallbackRows),
                );
            }

            return [
                'label' => 'Ad attribution',
                'title' => 'Exact acquisition comparison is available for matched IDs',
                'body' => $body,
                'next_step' => 'Use cost and campaign/ad comparisons only for the matched stable-ID rows; keep unmatched or name-fallback platform totals separate.',
                'tone' => 'positive',
            ];
        }

        if ($stableRows === 0 && $fallbackRows > 0) {
            return [
                'label' => 'Ad attribution',
                'title' => 'Imported ad data is aggregate-only',
                'body' => sprintf(
                    'The most recent %s import contains %s row(s), but no stable campaign/ad IDs. Spend and platform results are useful as period context, not exact ad-to-registration attribution.',
                    $platform,
                    number_format($rowCount),
                ),
                'next_step' => 'Use these platform totals for historical context and add the Engage tracking parameters to active/future ads for exact reconciliation.',
                'tone' => 'neutral',
            ];
        }

        return [
            'label' => 'Ad attribution',
            'title' => 'Stable ad IDs exist, but Engage has no exact match yet',
            'body' => sprintf(
                'The most recent %s import contains stable platform identity, but none of its stable rows matched retained tracked campaign traffic for that reporting period.',
                $platform,
            ),
            'next_step' => 'Check the ad tracking parameters and reporting-period overlap before using the import to judge campaign or creative performance.',
            'tone' => 'attention',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $funnel
     * @return array{from: string, to: string, from_key: string, to_key: string, drop: int, previous: int, percent: float}|null
     */
    private function largestFunnelDrop(array $funnel): ?array
    {
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

            if ($largestDrop === null
                || $drop > $largestDrop['drop']
                || ($drop === $largestDrop['drop'] && $dropPercent > $largestDrop['percent'])
            ) {
                $largestDrop = [
                    'from' => (string) $previous['label'],
                    'to' => (string) $current['label'],
                    'from_key' => (string) $previous['key'],
                    'to_key' => (string) $current['key'],
                    'drop' => $drop,
                    'previous' => $previousCount,
                    'percent' => $dropPercent,
                ];
            }
        }

        return $largestDrop;
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

        $largestDrop = $this->largestFunnelDrop($funnel);

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
     * @return array{
     *     highlights: array<int, array<string, mixed>>,
     *     groups: array<int, array<string, mixed>>,
     *     minimum_likely_human_sessions: int,
     *     minimum_gap_percentage_points: float
     * }
     */
    private function performanceComparisons(Collection $rows): array
    {
        $definitions = [
            ['key' => 'source', 'label' => 'Source / medium', 'slice' => 'campaign', 'level' => 'source'],
            ['key' => 'campaign', 'label' => 'Campaign', 'slice' => 'campaign', 'level' => 'campaign'],
            ['key' => 'group', 'label' => 'Ad set / ad group', 'slice' => 'campaign', 'level' => 'group'],
            ['key' => 'creative', 'label' => 'Creative / ad', 'slice' => 'campaign', 'level' => 'creative'],
            ['key' => 'placement', 'label' => 'Placement', 'slice' => 'campaign', 'level' => 'placement'],
            ['key' => 'landing_page', 'label' => 'Landing page', 'slice' => 'path', 'level' => 'path'],
            ['key' => 'presentation', 'label' => 'Page / registration presentation', 'slice' => 'presentation', 'level' => 'presentation'],
            ['key' => 'device', 'label' => 'Device class', 'slice' => 'device', 'level' => 'device'],
        ];
        $groups = [];

        foreach ($definitions as $definition) {
            $candidates = $this->comparisonCandidates(
                rows: $rows,
                slice: $definition['slice'],
                level: $definition['level'],
            );

            $groups[] = $this->comparisonGroup(
                key: $definition['key'],
                label: $definition['label'],
                candidates: $candidates,
            );
        }

        $highlights = collect($groups)
            ->filter(fn (array $group): bool => in_array(
                $group['status'],
                ['directional', 'similar'],
                true,
            ))
            ->sortByDesc(fn (array $group): float => (float) ($group['gap_percentage_points'] ?? 0.0))
            ->take(6)
            ->values()
            ->all();

        return [
            'highlights' => $highlights,
            'groups' => $groups,
            'minimum_likely_human_sessions' => self::COMPARISON_MIN_LIKELY_HUMAN_SESSIONS,
            'minimum_gap_percentage_points' => self::COMPARISON_MIN_GAP_PERCENTAGE_POINTS,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function comparisonCandidates(
        Collection $rows,
        string $slice,
        string $level,
    ): array {
        $groups = [];

        foreach ($this->metricRows(
            $rows,
            'webinar.registration_conversion',
            ['slice' => $slice, 'traffic_class' => 'likely_human'],
        ) as $row) {
            $dimensions = is_array($row->dimensions) ? $row->dimensions : [];
            $identity = $this->comparisonIdentity($dimensions, $slice, $level);

            if ($identity === null) {
                continue;
            }

            $canonical = $identity;
            ksort($canonical);
            $groupKey = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'identity' => $identity,
                    'label' => $this->comparisonLabel($dimensions, $slice, $level),
                    'numerator' => 0,
                    'denominator' => 0,
                ];
            } else {
                $label = $this->comparisonLabel($dimensions, $slice, $level);

                if ($label !== '') {
                    $groups[$groupKey]['label'] = $label;
                }
            }

            $groups[$groupKey]['numerator'] += max(0, (int) $row->numerator);
            $groups[$groupKey]['denominator'] += max(0, (int) ($row->denominator ?? 0));
        }

        $candidates = array_values(array_map(function (array $group): array {
            $denominator = (int) $group['denominator'];
            $numerator = (int) $group['numerator'];

            return [
                ...$group,
                'percent' => $denominator > 0
                    ? round(($numerator / $denominator) * 100, 1)
                    : null,
                'eligible' => $denominator >= self::COMPARISON_MIN_LIKELY_HUMAN_SESSIONS,
            ];
        }, $groups));

        usort($candidates, function (array $a, array $b): int {
            $percentComparison = ((float) ($b['percent'] ?? -1.0)) <=> ((float) ($a['percent'] ?? -1.0));

            return $percentComparison !== 0
                ? $percentComparison
                : ((int) $b['denominator'] <=> (int) $a['denominator']);
        });

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function comparisonGroup(
        string $key,
        string $label,
        array $candidates,
    ): array {
        $eligible = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ($candidate['eligible'] ?? false) === true
                && $candidate['percent'] !== null,
        ));

        if (count($eligible) < 2) {
            return [
                'key' => $key,
                'label' => $label,
                'status' => 'insufficient',
                'title' => 'More comparable traffic needed',
                'body' => sprintf(
                    '%s has %s observed variant(s), but only %s meet the %s likely-human landing-session comparison guardrail.',
                    $label,
                    number_format(count($candidates)),
                    number_format(count($eligible)),
                    number_format(self::COMPARISON_MIN_LIKELY_HUMAN_SESSIONS),
                ),
                'observed_count' => count($candidates),
                'eligible_count' => count($eligible),
                'gap_percentage_points' => null,
                'higher' => null,
                'lower' => null,
            ];
        }

        usort($eligible, fn (array $a, array $b): int =>
            ((float) $b['percent'] <=> (float) $a['percent'])
                ?: ((int) $b['denominator'] <=> (int) $a['denominator'])
        );

        $higher = $eligible[0];
        $lower = $eligible[count($eligible) - 1];
        $gap = round((float) $higher['percent'] - (float) $lower['percent'], 1);
        $directional = $gap >= self::COMPARISON_MIN_GAP_PERCENTAGE_POINTS;

        return [
            'key' => $key,
            'label' => $label,
            'status' => $directional ? 'directional' : 'similar',
            'title' => $directional
                ? sprintf('%s is converting higher than %s', $higher['label'], $lower['label'])
                : sprintf('%s variants are currently close', $label),
            'body' => sprintf(
                '%s: %s%% (%s/%s) versus %s: %s%% (%s/%s), a %s percentage-point gap.',
                $higher['label'],
                $this->formatPercent($higher['percent']),
                number_format((int) $higher['numerator']),
                number_format((int) $higher['denominator']),
                $lower['label'],
                $this->formatPercent($lower['percent']),
                number_format((int) $lower['numerator']),
                number_format((int) $lower['denominator']),
                number_format($gap, 1),
            ),
            'observed_count' => count($candidates),
            'eligible_count' => count($eligible),
            'gap_percentage_points' => $gap,
            'higher' => $higher,
            'lower' => $lower,
        ];
    }

    /**
     * @param array<string, mixed> $dimensions
     * @return array<string, scalar>|null
     */
    private function comparisonIdentity(
        array $dimensions,
        string $slice,
        string $level,
    ): ?array {
        if ($slice === 'path') {
            return filled($dimensions['path'] ?? null)
                ? ['path' => (string) $dimensions['path']]
                : null;
        }

        if ($slice === 'presentation') {
            $presentation = $this->comparisonString($dimensions['presentation'] ?? null);
            $revision = $this->comparisonString($dimensions['page_revision'] ?? null);

            return $presentation !== null || $revision !== null
                ? array_filter([
                    'presentation' => $presentation,
                    'page_revision' => $revision,
                ], fn (?string $value): bool => $value !== null)
                : null;
        }

        if ($slice === 'device') {
            return filled($dimensions['device_class'] ?? null)
                ? ['device_class' => (string) $dimensions['device_class']]
                : null;
        }

        if ($slice !== 'campaign') {
            return null;
        }

        $platform = $this->comparisonString($dimensions['external_platform'] ?? null);
        $source = $this->comparisonString($dimensions['utm_source'] ?? null);
        $medium = $this->comparisonString($dimensions['utm_medium'] ?? null);
        $campaignId = $this->comparisonString($dimensions['external_campaign_id'] ?? null);
        $campaignName = $this->comparisonString($dimensions['utm_campaign'] ?? null);
        $groupId = $this->comparisonString($dimensions['external_group_id'] ?? null);
        $groupName = $this->comparisonString($dimensions['utm_term'] ?? null);
        $creativeId = $this->comparisonString($dimensions['external_creative_id'] ?? null);
        $creativeName = $this->comparisonString($dimensions['utm_content'] ?? null);
        $placement = $this->comparisonString($dimensions['external_placement'] ?? null);

        return match ($level) {
            'source' => $source !== null || $medium !== null
                ? array_filter(['source' => $source, 'medium' => $medium], fn (?string $value): bool => $value !== null)
                : null,
            'campaign' => $campaignId !== null
                ? array_filter(['platform' => $platform, 'campaign_id' => $campaignId], fn (?string $value): bool => $value !== null)
                : ($campaignName !== null
                    ? array_filter(['source' => $source, 'medium' => $medium, 'campaign_name' => $campaignName], fn (?string $value): bool => $value !== null)
                    : null),
            'group' => $groupId !== null
                ? array_filter(['platform' => $platform, 'campaign_id' => $campaignId, 'group_id' => $groupId], fn (?string $value): bool => $value !== null)
                : ($groupName !== null
                    ? array_filter(['source' => $source, 'medium' => $medium, 'campaign_name' => $campaignName, 'group_name' => $groupName], fn (?string $value): bool => $value !== null)
                    : null),
            'creative' => $creativeId !== null
                ? array_filter(['platform' => $platform, 'campaign_id' => $campaignId, 'group_id' => $groupId, 'creative_id' => $creativeId], fn (?string $value): bool => $value !== null)
                : ($creativeName !== null
                    ? array_filter(['source' => $source, 'medium' => $medium, 'campaign_name' => $campaignName, 'group_name' => $groupName, 'creative_name' => $creativeName], fn (?string $value): bool => $value !== null)
                    : null),
            'placement' => $placement !== null
                ? array_filter(['platform' => $platform, 'placement' => $placement], fn (?string $value): bool => $value !== null)
                : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $dimensions */
    private function comparisonLabel(
        array $dimensions,
        string $slice,
        string $level,
    ): string {
        if ($slice === 'path') {
            return (string) ($dimensions['path'] ?? 'Unknown landing page');
        }

        if ($slice === 'presentation') {
            $parts = array_filter([
                $this->comparisonString($dimensions['presentation'] ?? null),
                $this->comparisonString($dimensions['page_revision'] ?? null),
            ]);

            return $parts !== []
                ? implode(' · ', array_map(
                    fn (string $value): string => str_replace('_', ' ', ucwords($value, "_-")),
                    $parts,
                ))
                : 'Unknown presentation';
        }

        if ($slice === 'device') {
            $device = $this->comparisonString($dimensions['device_class'] ?? null);

            return $device !== null
                ? str_replace('_', ' ', ucwords($device, "_-"))
                : 'Unknown device';
        }

        $source = $this->comparisonString($dimensions['utm_source'] ?? null);
        $medium = $this->comparisonString($dimensions['utm_medium'] ?? null);

        return match ($level) {
            'source' => implode(' / ', array_filter([$source, $medium])) ?: 'Unknown source',
            'campaign' => $this->comparisonString($dimensions['utm_campaign'] ?? null)
                ?? $this->comparisonString($dimensions['external_campaign_id'] ?? null)
                ?? 'Unknown campaign',
            'group' => $this->comparisonString($dimensions['utm_term'] ?? null)
                ?? $this->comparisonString($dimensions['external_group_id'] ?? null)
                ?? 'Unknown group',
            'creative' => $this->comparisonString($dimensions['utm_content'] ?? null)
                ?? $this->comparisonString($dimensions['external_creative_id'] ?? null)
                ?? 'Unknown creative',
            'placement' => ($value = $this->comparisonString($dimensions['external_placement'] ?? null)) !== null
                ? str_replace('_', ' ', ucwords($value, "_-"))
                : 'Unknown placement',
            default => 'Unknown',
        };
    }

    private function comparisonString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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

    /**
     * @return array<int, array<string, mixed>>
     */
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

        if ($externalRows->isEmpty()) {
            return [];
        }

        $groups = $externalRows->groupBy(function (ReportingExternalMeasurement $row): string {
            return implode('|', [
                $row->platform,
                $row->account_id ?? '',
                $row->period_start?->toDateString() ?? '',
                $row->period_end?->toDateString() ?? '',
                $row->currency ?? '',
                $row->source_file_hash ?? 'direct',
            ]);
        });

        $comparisons = [];

        foreach ($groups as $group) {
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
                ->where('metric_key', 'like', 'webinar.%')
                ->get();

            $stableRows = $group
                ->where('identity_quality', ReportingExternalMeasurement::IDENTITY_STABLE_IDS)
                ->values();
            $matchedRows = 0;
            $engageLandingSessions = 0;
            $engageRegistrations = 0;
            $matchedSpend = 0.0;
            $matchedSpendAvailable = false;
            $matchedLandingViews = 0;
            $matchedLandingViewsAvailable = false;

            foreach ($stableRows as $measurement) {
                $matching = $this->matchingCampaignRows($periodRows, $measurement);

                if ($matching->isEmpty()) {
                    continue;
                }

                $matchedRows++;
                $engageLandingSessions += $this->countMetric(
                    $matching,
                    'webinar.landing_sessions',
                    ['traffic_class' => 'likely_human'],
                );
                $conversion = $this->ratio(
                    $matching,
                    'webinar.registration_conversion',
                    ['traffic_class' => 'likely_human'],
                );
                $engageRegistrations += (int) $conversion['numerator'];

                if ($measurement->spend !== null) {
                    $matchedSpend += (float) $measurement->spend;
                    $matchedSpendAvailable = true;
                }

                if ($measurement->landing_page_views !== null) {
                    $matchedLandingViews += (int) $measurement->landing_page_views;
                    $matchedLandingViewsAvailable = true;
                }
            }

            $resultsByType = [];

            foreach ($group as $measurement) {
                if ($measurement->result_type === null || $measurement->results === null) {
                    continue;
                }

                $resultsByType[$measurement->result_type] = ($resultsByType[$measurement->result_type] ?? 0.0)
                    + (float) $measurement->results;
            }

            ksort($resultsByType);

            $comparisons[] = [
                'platform' => $first->platform,
                'account_id' => $first->account_id,
                'account_timezone' => $first->account_timezone,
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
                    'outbound_clicks' => $this->nullableIntegerSum($group, 'outbound_clicks'),
                    'landing_page_views' => $this->nullableIntegerSum($group, 'landing_page_views'),
                    'results_by_type' => $resultsByType,
                ],
                'exact_comparison' => [
                    'available' => $matchedRows > 0,
                    'engage_likely_human_sessions' => $engageLandingSessions,
                    'engage_registrations' => $engageRegistrations,
                    'matched_spend' => $matchedSpendAvailable ? round($matchedSpend, 4) : null,
                    'matched_landing_page_views' => $matchedLandingViewsAvailable ? $matchedLandingViews : null,
                    'cost_per_registration' => $matchedSpendAvailable && $engageRegistrations > 0
                        ? round($matchedSpend / $engageRegistrations, 2)
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

    private function nullableIntegerSum(Collection $rows, string $column): ?int
    {
        $available = $rows->filter(fn (ReportingExternalMeasurement $row): bool => $row->{$column} !== null);

        return $available->isEmpty()
            ? null
            : (int) $available->sum($column);
    }

    private function nullableNumericSum(Collection $rows, string $column): ?float
    {
        $available = $rows->filter(fn (ReportingExternalMeasurement $row): bool => $row->{$column} !== null);

        return $available->isEmpty()
            ? null
            : round((float) $available->sum(fn (ReportingExternalMeasurement $row): float => (float) $row->{$column}), 4);
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