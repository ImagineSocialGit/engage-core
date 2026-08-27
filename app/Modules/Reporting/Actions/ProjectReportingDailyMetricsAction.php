<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Modules\Reporting\Models\ReportingSession;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectReportingDailyMetricsAction
{
    public const PROJECTOR_KEY = 'public_funnel';

    public const PROJECTOR_VERSION = 4;

    public const METRIC_VERSION = 2;

    private const WEBINAR_SURFACE = 'webinar_registration';

    private const WEBINAR_FACT = 'webinar.registration';

    private const SCHEDULING_SURFACE = 'scheduling_public_booking';

    private const SCHEDULING_FACT = 'scheduling.public_booking';

    private const OWNED_METRIC_KEYS = [
        'webinar.landing_sessions',
        'webinar.traffic_share',
        'webinar.traffic_classification_resolution',
        'webinar.funnel_sessions',
        'webinar.registration_conversion',
        'webinar.attributed_registrations',
        'webinar.registration_attribution_evidence',
        'webinar.validation_failure_rate',
        'webinar.validation_failures',
        'webinar.throttled_requests',
        'webinar.bot_protection_results',
        'webinar.local_registrations',
        'webinar.registration_correlation_coverage',
        'webinar.registration_finalization_outcomes',
        'webinar.provider_completion',
        'webinar.provider_outcomes',
        'webinar.confirmation_planning',
        'webinar.confirmation_delivery',
        'webinar.confirmation_terminal_outcomes',
        'webinar.join_rate',
        'webinar.attendance_rate',
        'webinar.attendance_outcomes',
        'webinar.question_answers',
        'scheduling.landing_sessions',
        'scheduling.traffic_classification_resolution',
        'scheduling.funnel_sessions',
        'scheduling.booking_conversion',
        'scheduling.validation_failure_rate',
        'scheduling.validation_failures',
        'scheduling.availability_outcomes',
        'scheduling.verification_channels',
        'scheduling.public_appointments',
        'scheduling.appointment_outcomes',
        'scheduling.booking_correlation_coverage',
        'scheduling.attributed_appointments',
        'scheduling.booking_attribution_evidence',
    ];

    public function __construct(
        private readonly ReportingProjectionFactRegistry $facts,
    ) {}

    /**
     * @return array{days: int, metrics: int, projected_through: string}
     */
    public function handle(
        CarbonImmutable $fromDate,
        CarbonImmutable $throughDate,
    ): array {
        $timezone = $this->reportingTimezone();
        $fromDate = $fromDate->setTimezone($timezone)->startOfDay();
        $throughDate = $throughDate->setTimezone($timezone)->startOfDay();

        if ($throughDate->lessThan($fromDate)) {
            [$fromDate, $throughDate] = [$throughDate, $fromDate];
        }

        $projectedThrough = CarbonImmutable::now('UTC');
        $days = 0;
        $metricCount = 0;
        $date = $fromDate;

        while ($date->lessThanOrEqualTo($throughDate)) {
            $rows = $this->projectDay(
                localDate: $date,
                projectedThrough: $projectedThrough,
            );

            $this->replaceDay(
                metricDate: $date->toDateString(),
                rows: $rows,
            );

            $days++;
            $metricCount += count($rows);
            $date = $date->addDay();
        }

        ReportingProjectionCheckpoint::query()->updateOrCreate(
            [
                'projector_key' => self::PROJECTOR_KEY,
                'projector_version' => self::PROJECTOR_VERSION,
            ],
            [
                'cursor' => $throughDate->toDateString(),
                'window_start' => $fromDate->utc(),
                'window_end' => $throughDate->endOfDay()->utc(),
                'projected_through' => $projectedThrough,
                'meta' => [
                    'timezone' => $timezone,
                    'days' => $days,
                    'metrics' => $metricCount,
                ],
            ],
        );

        return [
            'days' => $days,
            'metrics' => $metricCount,
            'projected_through' => $projectedThrough->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectDay(
        CarbonImmutable $localDate,
        CarbonImmutable $projectedThrough,
    ): array {
        $localStart = $localDate->startOfDay();
        $localEnd = $localDate->endOfDay();
        $window = new ReportingProjectionWindow(
            startsAt: $localStart->utc(),
            endsAt: $localEnd->utc(),
        );

        $observations = ReportingObservation::query()
            ->with('session')
            ->where('surface', self::WEBINAR_SURFACE)
            ->whereBetween('occurred_at', [
                $window->startsAt,
                $window->endsAt,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $facts = collect(iterator_to_array(
            $this->facts->facts($window),
            false,
        ));
        $registrationFacts = $facts
            ->filter(fn (ReportingProjectionFact $fact): bool =>
                $fact->key === self::WEBINAR_FACT
                    && $fact->version === 1
            )
            ->values();
        $questionFacts = $facts
            ->filter(fn (ReportingProjectionFact $fact): bool =>
                $fact->key === 'webinar.question_response'
                    && $fact->version === 1
            )
            ->values();
        $schedulingFacts = $facts
            ->filter(fn (ReportingProjectionFact $fact): bool =>
                $fact->key === self::SCHEDULING_FACT
                    && $fact->version === 1
            )
            ->values();
        $schedulingObservations = ReportingObservation::query()
            ->with('session')
            ->where('surface', self::SCHEDULING_SURFACE)
            ->whereBetween('occurred_at', [
                $window->startsAt,
                $window->endsAt,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $metrics = [];
        $profiles = $this->landingProfiles($observations);

        $this->projectTrafficMetrics($metrics, $profiles);
        $this->projectBehavioralFunnel($metrics, $observations, $profiles);
        $this->projectDurableWebinarOutcomes(
            metrics: $metrics,
            facts: $registrationFacts,
            observations: $observations,
        );
        $this->projectQuestionAnswers(
            metrics: $metrics,
            facts: $questionFacts,
        );
        $this->projectSchedulingBrowserFunnel(
            metrics: $metrics,
            observations: $schedulingObservations,
        );
        $this->projectDurableSchedulingOutcomes(
            metrics: $metrics,
            facts: $schedulingFacts,
            observations: $schedulingObservations,
        );

        return collect($metrics)
            ->map(function (array $metric) use (
                $localDate,
                $projectedThrough,
            ): array {
                $dimensions = $this->canonicalDimensions(
                    $metric['dimensions'],
                );

                return [
                    'metric_date' => $localDate->toDateString(),
                    'metric_key' => $metric['metric_key'],
                    'metric_version' => self::METRIC_VERSION,
                    'dimension_hash' => hash(
                        'sha256',
                        json_encode(
                            $dimensions,
                            JSON_THROW_ON_ERROR
                                | JSON_UNESCAPED_SLASHES
                                | JSON_UNESCAPED_UNICODE,
                        ),
                    ),
                    'dimensions' => $dimensions,
                    'numerator' => max(0, (int) $metric['numerator']),
                    'denominator' => $metric['denominator'] === null
                        ? null
                        : max(0, (int) $metric['denominator']),
                    'projected_through' => $projectedThrough,
                ];
            })
            ->sortBy(fn (array $row): string =>
                $row['metric_key'].'|'.$row['dimension_hash']
            )
            ->values()
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param array<int, array<string, mixed>> $profiles
     */
    private function projectTrafficMetrics(
        array &$metrics,
        array $profiles,
    ): void {
        $totalsBySlice = [];

        foreach ($profiles as $profile) {
            $this->incrementCount(
                metrics: $metrics,
                metricKey: 'webinar.traffic_classification_resolution',
                dimensions: [
                    'slice' => 'all',
                    'recorded_traffic_class' => $profile['recorded_traffic_class'],
                    'effective_traffic_class' => $profile['traffic_class'],
                    'reason' => $profile['traffic_resolution_reason'],
                ],
            );

            foreach ($this->sessionSlices($profile) as $slice) {
                $totalKey = $this->dimensionIdentity($slice);
                $totalsBySlice[$totalKey] = [
                    'dimensions' => $slice,
                    'count' => ($totalsBySlice[$totalKey]['count'] ?? 0) + 1,
                ];

                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'webinar.landing_sessions',
                    dimensions: [
                        ...$slice,
                        'traffic_class' => $profile['traffic_class'],
                    ],
                );
            }
        }

        foreach ($totalsBySlice as $total) {
            $dimensions = $total['dimensions'];
            $totalCount = (int) $total['count'];

            foreach (['likely_human', 'likely_automated', 'unknown'] as $trafficClass) {
                $classCount = 0;

                foreach ($profiles as $profile) {
                    if ($profile['traffic_class'] !== $trafficClass) {
                        continue;
                    }

                    if ($this->profileHasSlice($profile, $dimensions)) {
                        $classCount++;
                    }
                }

                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'webinar.traffic_share',
                    dimensions: [
                        ...$dimensions,
                        'traffic_class' => $trafficClass,
                    ],
                    numerator: $classCount,
                    denominator: $totalCount,
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param Collection<int, ReportingObservation> $observations
     * @param array<int, array<string, mixed>> $profiles
     */
    private function projectBehavioralFunnel(
        array &$metrics,
        Collection $observations,
        array $profiles,
    ): void {
        $stepMap = [
            'webinar.page.view' => 'landing',
            'webinar.cta.click' => 'cta_click',
            'webinar.modal.open' => 'modal_open',
            'webinar.form.start' => 'form_start',
            'webinar.form.submit_attempt' => 'submit_attempt',
            'webinar.form.validation_failed' => 'validation_failed',
        ];
        $stepSessions = [];
        $submitAttemptsBySlice = [];
        $validationFailuresBySlice = [];

        foreach ($observations as $observation) {
            $sessionId = $observation->reporting_session_id;

            if ($sessionId === null || ! isset($profiles[$sessionId])) {
                $this->projectDiagnosticObservation(
                    metrics: $metrics,
                    observation: $observation,
                    profile: null,
                );

                continue;
            }

            $profile = $profiles[$sessionId];
            $this->projectDiagnosticObservation(
                metrics: $metrics,
                observation: $observation,
                profile: $profile,
            );

            if ($profile['traffic_class'] !== 'likely_human') {
                continue;
            }

            $step = $stepMap[$observation->event_key] ?? null;

            if ($step !== null) {
                $stepSessions[$step][$sessionId] = true;
            }

            if ($observation->event_key === 'webinar.form.submit_attempt') {
                foreach ($this->sessionSlices($profile) as $slice) {
                    $key = $this->dimensionIdentity($slice);
                    $submitAttemptsBySlice[$key] = [
                        'dimensions' => $slice,
                        'count' => ($submitAttemptsBySlice[$key]['count'] ?? 0) + 1,
                    ];
                }
            }

            if ($observation->event_key !== 'webinar.form.validation_failed') {
                continue;
            }

            foreach ($this->sessionSlices($profile) as $slice) {
                $key = $this->dimensionIdentity($slice);
                $validationFailuresBySlice[$key] = [
                    'dimensions' => $slice,
                    'count' => ($validationFailuresBySlice[$key]['count'] ?? 0) + 1,
                ];
            }

            $fieldKeys = data_get($observation->properties, 'field_keys', []);

            if (! is_array($fieldKeys)) {
                continue;
            }

            foreach (array_unique($fieldKeys) as $fieldKey) {
                if (! is_string($fieldKey) || trim($fieldKey) === '') {
                    continue;
                }

                foreach ($this->diagnosticSlices($profile) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.validation_failures',
                        dimensions: [
                            ...$slice,
                            'field_key' => mb_substr(trim($fieldKey), 0, 80),
                        ],
                    );
                }
            }
        }

        foreach ($stepSessions as $step => $sessionIds) {
            foreach (array_keys($sessionIds) as $sessionId) {
                $profile = $profiles[$sessionId] ?? null;

                if (! is_array($profile)) {
                    continue;
                }

                foreach ($this->sessionSlices($profile) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.funnel_sessions',
                        dimensions: [
                            ...$slice,
                            'traffic_class' => 'likely_human',
                            'step' => $step,
                        ],
                    );
                }
            }
        }

        $allSliceKeys = array_values(array_unique([
            ...array_keys($submitAttemptsBySlice),
            ...array_keys($validationFailuresBySlice),
        ]));

        foreach ($allSliceKeys as $sliceKey) {
            $slice = $submitAttemptsBySlice[$sliceKey]['dimensions']
                ?? $validationFailuresBySlice[$sliceKey]['dimensions']
                ?? ['slice' => 'all'];

            $this->incrementRatio(
                metrics: $metrics,
                metricKey: 'webinar.validation_failure_rate',
                dimensions: [
                    ...$slice,
                    'traffic_class' => 'likely_human',
                ],
                numerator: (int) (
                    $validationFailuresBySlice[$sliceKey]['count'] ?? 0
                ),
                denominator: (int) (
                    $submitAttemptsBySlice[$sliceKey]['count'] ?? 0
                ),
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param Collection<int, ReportingProjectionFact> $facts
     * @param Collection<int, ReportingObservation> $observations
     */
    private function projectDurableWebinarOutcomes(
        array &$metrics,
        Collection $facts,
        Collection $observations,
    ): void {
        $submitObservations = $this->correlatedSubmitObservations($facts);
        $convertedSessionIds = [];

        foreach ($facts as $fact) {
            if (($fact->values['public_registration'] ?? false) !== true) {
                continue;
            }

            $correlatedObservation = $fact->correlationId !== null
                ? $submitObservations->get(strtolower($fact->correlationId))
                : null;
            $correlatedSessionId = $correlatedObservation instanceof ReportingObservation
                ? $correlatedObservation->reporting_session_id
                : null;

            if ($correlatedSessionId !== null) {
                $convertedSessionIds[$correlatedSessionId] = true;
            }

            if ($correlatedObservation instanceof ReportingObservation
                && $correlatedObservation->session instanceof ReportingSession
            ) {
                $attributionProfile = $this->registrationAttributionProfile(
                    $correlatedObservation,
                );

                foreach ($this->sessionSlices($attributionProfile) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.attributed_registrations',
                        dimensions: $slice,
                    );

                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.registration_attribution_evidence',
                        dimensions: [
                            ...$slice,
                            'evidence' => 'session_correlation',
                        ],
                    );

                    if ($this->hasCampaignAttribution($attributionProfile)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'webinar.registration_attribution_evidence',
                            dimensions: [
                                ...$slice,
                                'evidence' => 'campaign_attribution',
                            ],
                        );
                    }

                    if (filled($attributionProfile['referrer_host'] ?? null)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'webinar.registration_attribution_evidence',
                            dimensions: [
                                ...$slice,
                                'evidence' => 'referrer_host',
                            ],
                        );
                    }

                    if ($this->hasMetaClickEvidence($attributionProfile)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'webinar.registration_attribution_evidence',
                            dimensions: [
                                ...$slice,
                                'evidence' => 'meta_click_id',
                            ],
                        );
                    }
                }
            }

            foreach ($this->producerSlices($fact) as $slice) {
                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'webinar.local_registrations',
                    dimensions: $slice,
                );

                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'webinar.registration_correlation_coverage',
                    dimensions: $slice,
                    numerator: $correlatedSessionId !== null ? 1 : 0,
                    denominator: 1,
                );

                $finalizationStatus = $this->factString(
                    $fact,
                    'finalization_status',
                    'unknown',
                );

                $finalizationReason = $this->factString(
                    $fact,
                    'finalization_reason',
                    '',
                );
                $finalizationDimensions = [
                    ...$slice,
                    'outcome' => $finalizationStatus,
                ];

                if ($finalizationReason !== '') {
                    $finalizationDimensions['reason'] = $finalizationReason;
                }

                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'webinar.registration_finalization_outcomes',
                    dimensions: $finalizationDimensions,
                );

                if (($fact->values['provider_required'] ?? false) === true) {
                    $providerStatus = $this->factString(
                        $fact,
                        'provider_sync_status',
                        'pending',
                    );

                    $this->incrementRatio(
                        metrics: $metrics,
                        metricKey: 'webinar.provider_completion',
                        dimensions: $slice,
                        numerator: $providerStatus === 'succeeded' ? 1 : 0,
                        denominator: 1,
                    );

                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.provider_outcomes',
                        dimensions: [
                            ...$slice,
                            'outcome' => $providerStatus,
                        ],
                    );
                }

                if (($fact->values['confirmation_eligible'] ?? false) === true) {
                    $this->incrementRatio(
                        metrics: $metrics,
                        metricKey: 'webinar.confirmation_planning',
                        dimensions: $slice,
                        numerator: ($fact->values['confirmation_planned'] ?? false)
                            ? 1
                            : 0,
                        denominator: 1,
                    );
                }

                $confirmationPlanned = $this->factInt(
                    $fact,
                    'confirmation_planned_count',
                );
                $confirmationSent = $this->factInt(
                    $fact,
                    'confirmation_sent_count',
                );

                if ($confirmationPlanned > 0) {
                    $this->incrementRatio(
                        metrics: $metrics,
                        metricKey: 'webinar.confirmation_delivery',
                        dimensions: $slice,
                        numerator: $confirmationSent,
                        denominator: $confirmationPlanned,
                    );

                    foreach ([
                        'sent' => 'confirmation_sent_count',
                        'skipped' => 'confirmation_skipped_count',
                        'failed' => 'confirmation_failed_count',
                        'unresolved' => 'confirmation_unresolved_count',
                    ] as $outcome => $valueKey) {
                        $count = $this->factInt($fact, $valueKey);

                        if ($count < 1) {
                            continue;
                        }

                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'webinar.confirmation_terminal_outcomes',
                            dimensions: [
                                ...$slice,
                                'outcome' => $outcome,
                            ],
                            amount: $count,
                        );
                    }
                }

                $registrationStatus = $this->factString(
                    $fact,
                    'registration_status',
                    'unknown',
                );

                if (($fact->values['occurrence_started'] ?? false) === true
                    && $registrationStatus !== 'cancelled'
                ) {
                    $this->incrementRatio(
                        metrics: $metrics,
                        metricKey: 'webinar.join_rate',
                        dimensions: $slice,
                        numerator: ($fact->values['join_confirmed'] ?? false)
                            ? 1
                            : 0,
                        denominator: 1,
                    );
                }

                if (($fact->values['attendance_finalized'] ?? false) === true
                    && $registrationStatus !== 'cancelled'
                ) {
                    $attendanceStatus = $this->factString(
                        $fact,
                        'attendance_status',
                        'unknown',
                    );

                    $this->incrementRatio(
                        metrics: $metrics,
                        metricKey: 'webinar.attendance_rate',
                        dimensions: $slice,
                        numerator: $attendanceStatus === 'attended' ? 1 : 0,
                        denominator: 1,
                    );

                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.attendance_outcomes',
                        dimensions: [
                            ...$slice,
                            'outcome' => $attendanceStatus,
                        ],
                    );
                }
            }
        }

        $profiles = $this->landingProfiles($observations);

        foreach ($profiles as $sessionId => $profile) {
            if ($profile['traffic_class'] !== 'likely_human') {
                continue;
            }

            foreach ($this->sessionSlices($profile) as $slice) {
                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'webinar.registration_conversion',
                    dimensions: [
                        ...$slice,
                        'traffic_class' => 'likely_human',
                    ],
                    numerator: isset($convertedSessionIds[$sessionId]) ? 1 : 0,
                    denominator: 1,
                );
            }
        }
    }

    /**
     * Resolve authoritative registration correlation against retained submit
     * observations, not only observations that happened inside the fact day.
     * This keeps attribution intact across local-midnight boundaries while the
     * registration fact remains authoritative for the conversion itself.
     *
     * @param Collection<int, ReportingProjectionFact> $facts
     * @return Collection<string, ReportingObservation>
     */
    private function correlatedSubmitObservations(Collection $facts): Collection
    {
        $correlationIds = $facts
            ->map(fn (ReportingProjectionFact $fact): ?string =>
                is_string($fact->correlationId) && trim($fact->correlationId) !== ''
                    ? trim($fact->correlationId)
                    : null
            )
            ->filter()
            ->unique()
            ->values();

        if ($correlationIds->isEmpty()) {
            return collect();
        }

        return ReportingObservation::query()
            ->with('session')
            ->where('surface', self::WEBINAR_SURFACE)
            ->where('event_key', 'webinar.form.submit_attempt')
            ->whereIn('event_id', $correlationIds->all())
            ->get()
            ->keyBy(fn (ReportingObservation $observation): string =>
                strtolower((string) $observation->event_id)
            );
    }

    /** @return array<string, mixed> */
    private function registrationAttributionProfile(
        ReportingObservation $observation,
    ): array {
        /** @var ReportingSession $session */
        $session = $observation->session;
        $properties = is_array($observation->properties)
            ? $observation->properties
            : [];
        $clickIdHashes = is_array($session->click_id_hashes)
            ? $session->click_id_hashes
            : [];

        return [
            'path' => $session->landing_path ?: $observation->path,
            'referrer_host' => $session->referrer_host,
            'utm_source' => $session->utm_source,
            'utm_medium' => $session->utm_medium,
            'utm_campaign' => $session->utm_campaign,
            'utm_content' => $session->utm_content,
            'utm_term' => $session->utm_term,
            'external_platform' => $session->external_platform,
            'external_campaign_id' => $session->external_campaign_id,
            'external_group_id' => $session->external_group_id,
            'external_creative_id' => $session->external_creative_id,
            'external_placement' => $session->external_placement,
            'page_revision' => $properties['page_revision'] ?? null,
            'presentation' => $properties['presentation'] ?? null,
            'device_class' => $session->device_class,
            'click_id_hashes' => $clickIdHashes,
        ];
    }

    /** @param array<string, mixed> $profile */
    private function hasCampaignAttribution(array $profile): bool
    {
        foreach ([
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
        ] as $key) {
            if (filled($profile[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $profile */
    private function hasMetaClickEvidence(array $profile): bool
    {
        $hashes = is_array($profile['click_id_hashes'] ?? null)
            ? $profile['click_id_hashes']
            : [];

        return filled($hashes['meta_fbclid'] ?? null);
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param Collection<int, ReportingObservation> $observations
     */
    private function projectSchedulingBrowserFunnel(
        array &$metrics,
        Collection $observations,
    ): void {
        $profiles = $this->schedulingProfiles($observations);
        $stepMap = [
            'scheduling.booking.service_selected' => 'service_selected',
            'scheduling.booking.availability_viewed' => 'availability_viewed',
            'scheduling.booking.time_selected' => 'time_selected',
            'scheduling.booking.details_started' => 'details_started',
            'scheduling.booking.submit_attempt' => 'submit_attempt',
        ];
        $stepSessions = [];
        $submitSessions = [];
        $validationSessions = [];
        $availabilitySessions = [];
        $verificationSessions = [];

        foreach ($profiles as $sessionId => $profile) {
            $this->incrementCount(
                metrics: $metrics,
                metricKey: 'scheduling.traffic_classification_resolution',
                dimensions: [
                    'slice' => 'all',
                    'recorded_traffic_class' => $profile['recorded_traffic_class'],
                    'effective_traffic_class' => $profile['traffic_class'],
                    'reason' => $profile['traffic_resolution_reason'],
                ],
            );

            foreach ($this->schedulingSessionSlices($profile) as $slice) {
                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'scheduling.landing_sessions',
                    dimensions: [
                        ...$slice,
                        'traffic_class' => $profile['traffic_class'],
                    ],
                );

                if ($profile['traffic_class'] === 'likely_human') {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'scheduling.funnel_sessions',
                        dimensions: [
                            ...$slice,
                            'traffic_class' => 'likely_human',
                            'step' => 'catalog',
                        ],
                    );
                }
            }
        }

        foreach ($observations as $observation) {
            $sessionId = $observation->reporting_session_id;

            if ($sessionId === null || ! isset($profiles[$sessionId])) {
                continue;
            }

            $profile = $profiles[$sessionId];

            if ($profile['traffic_class'] !== 'likely_human') {
                continue;
            }

            $properties = is_array($observation->properties)
                ? $observation->properties
                : [];
            $serviceKey = is_string($properties['service_key'] ?? null)
                && trim((string) $properties['service_key']) !== ''
                    ? mb_substr(trim((string) $properties['service_key']), 0, 100)
                    : ($profile['service_key'] ?? null);
            $step = $stepMap[$observation->event_key] ?? null;

            if ($step !== null) {
                $stepSessions[$step][$sessionId] = $serviceKey;
            }

            if ($observation->event_key === 'scheduling.booking.submit_attempt') {
                $submitSessions[$sessionId] = $serviceKey;
            }

            if ($observation->event_key === 'scheduling.booking.validation_failed') {
                $validationSessions[$sessionId] = $serviceKey;
                $fieldKeys = $properties['field_keys'] ?? [];

                if (is_array($fieldKeys)) {
                    foreach (array_unique($fieldKeys) as $fieldKey) {
                        if (! is_string($fieldKey) || trim($fieldKey) === '') {
                            continue;
                        }

                        foreach ($this->schedulingDiagnosticSlices($profile, $serviceKey) as $slice) {
                            $this->incrementCount(
                                metrics: $metrics,
                                metricKey: 'scheduling.validation_failures',
                                dimensions: [
                                    ...$slice,
                                    'field_key' => mb_substr(trim($fieldKey), 0, 80),
                                ],
                            );
                        }
                    }
                }
            }

            if ($observation->event_key === 'scheduling.booking.availability_viewed') {
                $state = is_string($properties['availability_state'] ?? null)
                    ? trim((string) $properties['availability_state'])
                    : '';

                if ($state !== '') {
                    $availabilitySessions[$state][$sessionId] = $serviceKey;
                }
            }

            if (in_array($observation->event_key, [
                'scheduling.booking.verification_requested',
                'scheduling.booking.verification_completed',
            ], true)) {
                $channel = is_string($properties['channel'] ?? null)
                    ? trim((string) $properties['channel'])
                    : '';
                $stage = $observation->event_key === 'scheduling.booking.verification_completed'
                    ? 'completed'
                    : 'requested';

                if ($channel !== '') {
                    $verificationSessions[$stage][$channel][$sessionId] = $serviceKey;
                }
            }
        }

        foreach ($stepSessions as $step => $sessionIds) {
            foreach ($sessionIds as $sessionId => $serviceKey) {
                $profile = $profiles[$sessionId] ?? null;

                if (! is_array($profile)) {
                    continue;
                }

                foreach ($this->schedulingSessionSlices($profile, $serviceKey) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'scheduling.funnel_sessions',
                        dimensions: [
                            ...$slice,
                            'traffic_class' => 'likely_human',
                            'step' => $step,
                        ],
                    );
                }
            }
        }

        foreach ($profiles as $sessionId => $profile) {
            if ($profile['traffic_class'] !== 'likely_human') {
                continue;
            }

            $serviceKey = $submitSessions[$sessionId]
                ?? $validationSessions[$sessionId]
                ?? $profile['service_key']
                ?? null;

            if (! array_key_exists($sessionId, $submitSessions)
                && ! array_key_exists($sessionId, $validationSessions)
            ) {
                continue;
            }

            foreach ($this->schedulingSessionSlices($profile, $serviceKey) as $slice) {
                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'scheduling.validation_failure_rate',
                    dimensions: [
                        ...$slice,
                        'traffic_class' => 'likely_human',
                    ],
                    numerator: array_key_exists($sessionId, $validationSessions) ? 1 : 0,
                    denominator: array_key_exists($sessionId, $submitSessions) ? 1 : 0,
                );
            }
        }

        foreach ($availabilitySessions as $state => $sessionIds) {
            foreach ($sessionIds as $sessionId => $serviceKey) {
                $profile = $profiles[$sessionId] ?? null;

                if (! is_array($profile)) {
                    continue;
                }

                foreach ($this->schedulingDiagnosticSlices($profile, $serviceKey) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'scheduling.availability_outcomes',
                        dimensions: [...$slice, 'outcome' => mb_substr($state, 0, 80)],
                    );
                }
            }
        }

        foreach ($verificationSessions as $stage => $channels) {
            foreach ($channels as $channel => $sessionIds) {
                foreach ($sessionIds as $sessionId => $serviceKey) {
                    $profile = $profiles[$sessionId] ?? null;

                    if (! is_array($profile)) {
                        continue;
                    }

                    foreach ($this->schedulingDiagnosticSlices($profile, $serviceKey) as $slice) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'scheduling.verification_channels',
                            dimensions: [
                                ...$slice,
                                'stage' => mb_substr($stage, 0, 80),
                                'channel' => mb_substr($channel, 0, 80),
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param Collection<int, ReportingProjectionFact> $facts
     * @param Collection<int, ReportingObservation> $observations
     */
    private function projectDurableSchedulingOutcomes(
        array &$metrics,
        Collection $facts,
        Collection $observations,
    ): void {
        $submitObservations = $this->correlatedSchedulingSubmitObservations($facts);
        $convertedSessionIds = [];

        foreach ($facts as $fact) {
            $correlatedObservation = $fact->correlationId !== null
                ? $submitObservations->get(strtolower($fact->correlationId))
                : null;
            $correlatedSessionId = $correlatedObservation instanceof ReportingObservation
                ? $correlatedObservation->reporting_session_id
                : null;
            $serviceKey = is_string($fact->dimensions['service_key'] ?? null)
                ? trim((string) $fact->dimensions['service_key'])
                : null;

            if ($correlatedSessionId !== null) {
                $convertedSessionIds[$correlatedSessionId] = true;
            }

            if ($correlatedObservation instanceof ReportingObservation
                && $correlatedObservation->session instanceof ReportingSession
            ) {
                $profile = $this->registrationAttributionProfile($correlatedObservation);
                $profile['service_key'] = $serviceKey;

                foreach ($this->schedulingSessionSlices($profile, $serviceKey) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'scheduling.attributed_appointments',
                        dimensions: $slice,
                    );
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'scheduling.booking_attribution_evidence',
                        dimensions: [...$slice, 'evidence' => 'session_correlation'],
                    );

                    if ($this->hasCampaignAttribution($profile)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'scheduling.booking_attribution_evidence',
                            dimensions: [...$slice, 'evidence' => 'campaign_attribution'],
                        );
                    }

                    if (filled($profile['referrer_host'] ?? null)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'scheduling.booking_attribution_evidence',
                            dimensions: [...$slice, 'evidence' => 'referrer_host'],
                        );
                    }

                    if ($this->hasMetaClickEvidence($profile)) {
                        $this->incrementCount(
                            metrics: $metrics,
                            metricKey: 'scheduling.booking_attribution_evidence',
                            dimensions: [...$slice, 'evidence' => 'meta_click_id'],
                        );
                    }
                }
            }

            foreach ($this->schedulingProducerSlices($fact) as $slice) {
                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'scheduling.public_appointments',
                    dimensions: $slice,
                );
                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'scheduling.appointment_outcomes',
                    dimensions: [
                        ...$slice,
                        'outcome' => $this->factString($fact, 'appointment_status', 'unknown'),
                    ],
                );
                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'scheduling.booking_correlation_coverage',
                    dimensions: $slice,
                    numerator: $correlatedSessionId !== null ? 1 : 0,
                    denominator: 1,
                );
            }
        }

        $profiles = $this->schedulingProfiles($observations);

        foreach ($profiles as $sessionId => $profile) {
            if ($profile['traffic_class'] !== 'likely_human') {
                continue;
            }

            foreach ($this->schedulingSessionSlices($profile) as $slice) {
                $this->incrementRatio(
                    metrics: $metrics,
                    metricKey: 'scheduling.booking_conversion',
                    dimensions: [
                        ...$slice,
                        'traffic_class' => 'likely_human',
                    ],
                    numerator: isset($convertedSessionIds[$sessionId]) ? 1 : 0,
                    denominator: 1,
                );
            }
        }
    }

    /** @return Collection<string, ReportingObservation> */
    private function correlatedSchedulingSubmitObservations(Collection $facts): Collection
    {
        $correlationIds = $facts
            ->map(fn (ReportingProjectionFact $fact): ?string =>
                is_string($fact->correlationId) && trim($fact->correlationId) !== ''
                    ? trim($fact->correlationId)
                    : null
            )
            ->filter()
            ->unique()
            ->values();

        if ($correlationIds->isEmpty()) {
            return collect();
        }

        return ReportingObservation::query()
            ->with('session')
            ->where('surface', self::SCHEDULING_SURFACE)
            ->where('event_key', 'scheduling.booking.submit_attempt')
            ->whereIn('event_id', $correlationIds->all())
            ->get()
            ->keyBy(fn (ReportingObservation $observation): string =>
                strtolower((string) $observation->event_id)
            );
    }

    /**
     * @param Collection<int, ReportingObservation> $observations
     * @return array<int, array<string, mixed>>
     */
    private function schedulingProfiles(Collection $observations): array
    {
        $profiles = [];
        $observationsBySession = $observations
            ->filter(fn (ReportingObservation $observation): bool =>
                $observation->reporting_session_id !== null
            )
            ->groupBy(fn (ReportingObservation $observation): int =>
                (int) $observation->reporting_session_id
            );

        foreach ($observations
            ->where('event_key', 'scheduling.booking.page_view')
            ->sortBy([
                ['occurred_at', 'asc'],
                ['id', 'asc'],
            ]) as $observation) {
            $session = $observation->session;

            if ($session === null || isset($profiles[$session->getKey()])) {
                continue;
            }

            $sessionId = (int) $session->getKey();
            $sessionObservations = $observationsBySession->get($sessionId, collect());
            $classification = $this->effectiveTrafficClassification(
                session: $session,
                observations: $sessionObservations,
            );
            $properties = is_array($observation->properties)
                ? $observation->properties
                : [];
            $serviceObservation = $sessionObservations->first(function (ReportingObservation $candidate): bool {
                $candidateProperties = is_array($candidate->properties)
                    ? $candidate->properties
                    : [];

                return is_string($candidateProperties['service_key'] ?? null)
                    && trim((string) $candidateProperties['service_key']) !== '';
            });
            $serviceProperties = $serviceObservation instanceof ReportingObservation
                && is_array($serviceObservation->properties)
                    ? $serviceObservation->properties
                    : [];

            $profiles[$sessionId] = [
                'path' => $session->landing_path ?: $observation->path,
                'recorded_traffic_class' => $classification['recorded_class'],
                'traffic_class' => $classification['effective_class'],
                'traffic_resolution_reason' => $classification['reason'],
                'referrer_host' => $session->referrer_host,
                'utm_source' => $session->utm_source,
                'utm_medium' => $session->utm_medium,
                'utm_campaign' => $session->utm_campaign,
                'utm_content' => $session->utm_content,
                'utm_term' => $session->utm_term,
                'external_platform' => $session->external_platform,
                'external_campaign_id' => $session->external_campaign_id,
                'external_group_id' => $session->external_group_id,
                'external_creative_id' => $session->external_creative_id,
                'external_placement' => $session->external_placement,
                'page_revision' => $properties['page_revision'] ?? null,
                'presentation' => null,
                'device_class' => $session->device_class,
                'service_key' => is_string($serviceProperties['service_key'] ?? null)
                    ? mb_substr(trim((string) $serviceProperties['service_key']), 0, 100)
                    : null,
            ];
        }

        return $profiles;
    }

    /** @return array<int, array<string, scalar|null>> */
    private function schedulingSessionSlices(
        array $profile,
        ?string $serviceKey = null,
    ): array {
        $slices = $this->sessionSlices($profile);
        $serviceKey = filled($serviceKey)
            ? $serviceKey
            : ($profile['service_key'] ?? null);

        if (is_string($serviceKey) && trim($serviceKey) !== '') {
            $slices[] = [
                'slice' => 'service',
                'service_key' => mb_substr(trim($serviceKey), 0, 100),
            ];
        }

        return $slices;
    }

    /** @return array<int, array<string, scalar|null>> */
    private function schedulingDiagnosticSlices(
        array $profile,
        ?string $serviceKey = null,
    ): array {
        $slices = [['slice' => 'all']];
        $serviceKey = filled($serviceKey)
            ? $serviceKey
            : ($profile['service_key'] ?? null);

        if (is_string($serviceKey) && trim($serviceKey) !== '') {
            $slices[] = [
                'slice' => 'service',
                'service_key' => mb_substr(trim($serviceKey), 0, 100),
            ];
        }

        return $slices;
    }

    /** @return array<int, array<string, scalar|null>> */
    private function schedulingProducerSlices(ReportingProjectionFact $fact): array
    {
        $slices = [['slice' => 'all']];
        $serviceKey = $fact->dimensions['service_key'] ?? null;

        if (is_string($serviceKey) && trim($serviceKey) !== '') {
            $slices[] = [
                'slice' => 'service',
                'service_id' => $fact->dimensions['service_id'] ?? null,
                'service_key' => mb_substr(trim($serviceKey), 0, 100),
            ];
        }

        return $slices;
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param Collection<int, ReportingProjectionFact> $facts
     */
    private function projectQuestionAnswers(
        array &$metrics,
        Collection $facts,
    ): void {
        foreach ($facts as $fact) {
            $questionKey = $this->factString($fact, 'question_key', '');
            $answerKey = $this->factString($fact, 'answer_key', '');
            $definitionVersion = $this->factString(
                $fact,
                'definition_version',
                '',
            );

            if ($questionKey === ''
                || $answerKey === ''
                || $definitionVersion === ''
            ) {
                continue;
            }

            foreach ($this->producerSlices($fact) as $slice) {
                $this->incrementCount(
                    metrics: $metrics,
                    metricKey: 'webinar.question_answers',
                    dimensions: [
                        ...$slice,
                        'question_key' => $questionKey,
                        'answer_key' => $answerKey,
                        'definition_version' => $definitionVersion,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param array<string, mixed>|null $profile
     */
    private function projectDiagnosticObservation(
        array &$metrics,
        ReportingObservation $observation,
        ?array $profile,
    ): void {
        if ($observation->event_key === 'webinar.request.throttled') {
            $reason = data_get($observation->properties, 'reason');

            if (is_string($reason) && trim($reason) !== '') {
                foreach ($this->diagnosticSlices(
                    $profile ?? ['path' => $observation->path],
                ) as $slice) {
                    $this->incrementCount(
                        metrics: $metrics,
                        metricKey: 'webinar.throttled_requests',
                        dimensions: [
                            ...$slice,
                            'reason' => mb_substr(trim($reason), 0, 80),
                        ],
                    );
                }
            }
        }

        if ($observation->event_key !== 'webinar.bot_protection.result') {
            return;
        }

        $outcome = data_get($observation->properties, 'outcome');

        if (! is_string($outcome) || trim($outcome) === '') {
            return;
        }

        foreach ($this->diagnosticSlices(
            $profile ?? ['path' => $observation->path],
        ) as $slice) {
            $this->incrementCount(
                metrics: $metrics,
                metricKey: 'webinar.bot_protection_results',
                dimensions: [
                    ...$slice,
                    'outcome' => mb_substr(trim($outcome), 0, 80),
                ],
            );
        }
    }

    /**
     * @param Collection<int, ReportingObservation> $observations
     * @return array<int, array<string, mixed>>
     */
    private function landingProfiles(Collection $observations): array
    {
        $profiles = [];
        $observationsBySession = $observations
            ->filter(fn (ReportingObservation $observation): bool =>
                $observation->reporting_session_id !== null
            )
            ->groupBy(fn (ReportingObservation $observation): int =>
                (int) $observation->reporting_session_id
            );

        foreach ($observations
            ->where('event_key', 'webinar.page.view')
            ->sortBy([
                ['occurred_at', 'asc'],
                ['id', 'asc'],
            ]) as $observation) {
            $session = $observation->session;

            if ($session === null || isset($profiles[$session->getKey()])) {
                continue;
            }

            $properties = is_array($observation->properties)
                ? $observation->properties
                : [];
            $sessionId = (int) $session->getKey();
            $classification = $this->effectiveTrafficClassification(
                session: $session,
                observations: $observationsBySession->get($sessionId, collect()),
            );

            $profiles[$sessionId] = [
                'path' => $observation->path,
                'recorded_traffic_class' => $classification['recorded_class'],
                'traffic_class' => $classification['effective_class'],
                'traffic_resolution_reason' => $classification['reason'],
                'referrer_host' => $session->referrer_host,
                'utm_source' => $session->utm_source,
                'utm_medium' => $session->utm_medium,
                'utm_campaign' => $session->utm_campaign,
                'utm_content' => $session->utm_content,
                'utm_term' => $session->utm_term,
                'external_platform' => $session->external_platform,
                'external_campaign_id' => $session->external_campaign_id,
                'external_group_id' => $session->external_group_id,
                'external_creative_id' => $session->external_creative_id,
                'external_placement' => $session->external_placement,
                'page_revision' => $properties['page_revision'] ?? null,
                'presentation' => $properties['presentation'] ?? null,
                'device_class' => $session->device_class,
            ];
        }

        return $profiles;
    }

    /**
     * Raw request classification remains immutable. Projection may resolve an
     * unknown session for analysis when retained evidence is strong enough to
     * explain the calibration without inventing visitor identity.
     *
     * @param Collection<int, ReportingObservation> $observations
     * @return array{recorded_class: string, effective_class: string, reason: string}
     */
    private function effectiveTrafficClassification(
        ReportingSession $session,
        Collection $observations,
    ): array {
        $recordedClass = in_array($session->traffic_class, [
            'likely_human',
            'likely_automated',
            'unknown',
        ], true)
            ? (string) $session->traffic_class
            : 'unknown';

        if ($recordedClass !== 'unknown') {
            return [
                'recorded_class' => $recordedClass,
                'effective_class' => $recordedClass,
                'reason' => 'recorded_'.$recordedClass,
            ];
        }

        $serverRejected = $observations->contains(function (ReportingObservation $observation): bool {
            return $observation->event_key === 'webinar.bot_protection.result'
                && data_get($observation->properties, 'outcome') === 'server_rejected';
        });

        if ($serverRejected) {
            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'unknown',
                'reason' => 'server_bot_protection_rejected',
            ];
        }

        $clientPassed = $observations->contains(function (ReportingObservation $observation): bool {
            return $observation->event_key === 'webinar.bot_protection.result'
                && data_get($observation->properties, 'outcome') === 'client_passed';
        });

        if ($clientPassed) {
            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'likely_human',
                'reason' => 'client_bot_check_passed',
            ];
        }

        $interactiveSubmit = $observations->contains(function (ReportingObservation $observation): bool {
            return ($observation->event_key === 'webinar.form.submit_attempt'
                    && data_get($observation->properties, 'bot_interacted') === true)
                || $observation->event_key === 'scheduling.booking.submit_attempt';
        });

        if ($interactiveSubmit) {
            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'likely_human',
                'reason' => 'interactive_submit_evidence',
            ];
        }

        $activeEngagement = $observations->first(function (ReportingObservation $observation): bool {
            if ($observation->event_key !== 'webinar.engagement.signal') {
                return false;
            }

            return in_array(
                data_get($observation->properties, 'signal'),
                ['active_10s', 'scroll_25'],
                true,
            );
        });

        if ($activeEngagement instanceof ReportingObservation) {
            $signal = (string) data_get($activeEngagement->properties, 'signal');

            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'likely_human',
                'reason' => $signal === 'scroll_25'
                    ? 'scroll_depth_evidence'
                    : 'active_time_evidence',
            ];
        }

        $formInteracted = $observations->contains(
            fn (ReportingObservation $observation): bool =>
                in_array($observation->event_key, [
                    'webinar.form.start',
                    'scheduling.booking.service_selected',
                    'scheduling.booking.time_selected',
                    'scheduling.booking.details_started',
                ], true)
        );

        if ($formInteracted) {
            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'likely_human',
                'reason' => 'active_form_interaction',
            ];
        }

        $recordedReasons = is_array($session->classification_reasons)
            ? array_values(array_filter(
                $session->classification_reasons,
                fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '',
            ))
            : [];

        if (in_array('user_agent_unrecognized', $recordedReasons, true)
            && in_array($session->device_class, ['mobile', 'tablet'], true)
            && in_array($session->os_family, ['iOS', 'Android'], true)
        ) {
            return [
                'recorded_class' => 'unknown',
                'effective_class' => 'likely_human',
                'reason' => 'mobile_webview_evidence',
            ];
        }

        return [
            'recorded_class' => 'unknown',
            'effective_class' => 'unknown',
            'reason' => $recordedReasons[0] ?? 'insufficient_evidence',
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, array<string, scalar|null>>
     */
    private function sessionSlices(array $profile): array
    {
        $slices = [[
            'slice' => 'all',
        ]];

        if (filled($profile['path'] ?? null)) {
            $slices[] = [
                'slice' => 'path',
                'path' => $profile['path'],
            ];
        }

        $hasCampaignIdentity = $this->hasCampaignAttribution($profile);

        if ($hasCampaignIdentity || filled($profile['referrer_host'] ?? null)) {
            $campaignSlice = [
                'slice' => 'campaign',
                'utm_source' => $profile['utm_source'] ?? null,
                'utm_medium' => $profile['utm_medium'] ?? null,
                'utm_campaign' => $profile['utm_campaign'] ?? null,
                'utm_content' => $profile['utm_content'] ?? null,
                'utm_term' => $profile['utm_term'] ?? null,
                'external_platform' => $profile['external_platform'] ?? null,
                'external_campaign_id' => $profile['external_campaign_id'] ?? null,
                'external_group_id' => $profile['external_group_id'] ?? null,
                'external_creative_id' => $profile['external_creative_id'] ?? null,
                'external_placement' => $profile['external_placement'] ?? null,
            ];

            // Referrer is a fallback source dimension for untagged acquisition.
            // Do not fragment already-tagged paid campaigns by browser referrer host.
            if (! $hasCampaignIdentity && filled($profile['referrer_host'] ?? null)) {
                $campaignSlice['referrer_host'] = $profile['referrer_host'];
            }

            $slices[] = $campaignSlice;
        }

        if (filled($profile['page_revision'] ?? null)
            || filled($profile['presentation'] ?? null)
        ) {
            $slices[] = [
                'slice' => 'presentation',
                'page_revision' => $profile['page_revision'] ?? null,
                'presentation' => $profile['presentation'] ?? null,
            ];
        }

        if (filled($profile['device_class'] ?? null)) {
            $slices[] = [
                'slice' => 'device',
                'device_class' => $profile['device_class'],
            ];
        }

        return $slices;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, array<string, scalar|null>>
     */
    private function diagnosticSlices(array $profile): array
    {
        $slices = [[
            'slice' => 'all',
        ]];

        if (filled($profile['path'] ?? null)) {
            $slices[] = [
                'slice' => 'path',
                'path' => $profile['path'],
            ];
        }

        if (filled($profile['page_revision'] ?? null)
            || filled($profile['presentation'] ?? null)
        ) {
            $slices[] = [
                'slice' => 'presentation',
                'page_revision' => $profile['page_revision'] ?? null,
                'presentation' => $profile['presentation'] ?? null,
            ];
        }

        return $slices;
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function producerSlices(ReportingProjectionFact $fact): array
    {
        $slices = [[
            'slice' => 'all',
        ]];

        if (filled($fact->dimensions['series_slug'] ?? null)) {
            $slices[] = [
                'slice' => 'series',
                'series_id' => $fact->dimensions['series_id'] ?? null,
                'series_slug' => $fact->dimensions['series_slug'],
            ];
        }

        if (filled($fact->dimensions['occurrence_id'] ?? null)) {
            $slices[] = [
                'slice' => 'occurrence',
                'series_id' => $fact->dimensions['series_id'] ?? null,
                'series_slug' => $fact->dimensions['series_slug'] ?? null,
                'occurrence_id' => $fact->dimensions['occurrence_id'],
                'occurrence_slug' => $fact->dimensions['occurrence_slug'] ?? null,
            ];
        }

        return $slices;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, scalar|null> $slice
     */
    private function profileHasSlice(array $profile, array $slice): bool
    {
        return match ($slice['slice'] ?? 'all') {
            'all' => true,
            'path' => ($profile['path'] ?? null) === ($slice['path'] ?? null),
            'campaign' => $this->campaignProfileMatchesSlice($profile, $slice),
            'presentation' =>
                ($profile['page_revision'] ?? null) === ($slice['page_revision'] ?? null)
                && ($profile['presentation'] ?? null) === ($slice['presentation'] ?? null),
            'device' =>
                ($profile['device_class'] ?? null) === ($slice['device_class'] ?? null),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, scalar|null> $slice
     */
    private function campaignProfileMatchesSlice(array $profile, array $slice): bool
    {
        foreach ([
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
        ] as $key) {
            if (($profile[$key] ?? null) !== ($slice[$key] ?? null)) {
                return false;
            }
        }

        return ! array_key_exists('referrer_host', $slice)
            || ($profile['referrer_host'] ?? null) === $slice['referrer_host'];
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param array<string, scalar|null> $dimensions
     */
    private function incrementCount(
        array &$metrics,
        string $metricKey,
        array $dimensions,
        int $amount = 1,
    ): void {
        $identity = $metricKey.'|'.$this->dimensionIdentity($dimensions);

        if (! isset($metrics[$identity])) {
            $metrics[$identity] = [
                'metric_key' => $metricKey,
                'dimensions' => $dimensions,
                'numerator' => 0,
                'denominator' => null,
            ];
        }

        $metrics[$identity]['numerator'] += $amount;
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param array<string, scalar|null> $dimensions
     */
    private function incrementRatio(
        array &$metrics,
        string $metricKey,
        array $dimensions,
        int $numerator,
        int $denominator,
    ): void {
        $identity = $metricKey.'|'.$this->dimensionIdentity($dimensions);

        if (! isset($metrics[$identity])) {
            $metrics[$identity] = [
                'metric_key' => $metricKey,
                'dimensions' => $dimensions,
                'numerator' => 0,
                'denominator' => 0,
            ];
        }

        $metrics[$identity]['numerator'] += $numerator;
        $metrics[$identity]['denominator'] += $denominator;
    }

    /**
     * @param array<string, scalar|null> $dimensions
     */
    private function dimensionIdentity(array $dimensions): string
    {
        return json_encode(
            $this->canonicalDimensions($dimensions),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param array<string, scalar|null> $dimensions
     * @return array<string, scalar|null>
     */
    private function canonicalDimensions(array $dimensions): array
    {
        $dimensions = array_filter(
            $dimensions,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        ksort($dimensions);

        return $dimensions;
    }

    private function factString(
        ReportingProjectionFact $fact,
        string $key,
        string $default,
    ): string {
        $value = $fact->values[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    private function factInt(
        ReportingProjectionFact $fact,
        string $key,
    ): int {
        $value = $fact->values[$key] ?? 0;

        return is_int($value)
            ? max(0, $value)
            : (is_numeric($value) ? max(0, (int) $value) : 0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function replaceDay(string $metricDate, array $rows): void
    {
        DB::transaction(function () use ($metricDate, $rows): void {
            ReportingDailyMetric::query()
                ->where('metric_date', $metricDate)
                ->where('metric_version', '<=', self::METRIC_VERSION)
                ->whereIn('metric_key', self::OWNED_METRIC_KEYS)
                ->delete();

            foreach ($rows as $row) {
                ReportingDailyMetric::query()->create($row);
            }
        });
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