<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Actions\RecordReportingObservationAction;
use App\Modules\Reporting\Data\NormalizedReportingAttribution;
use App\Modules\Reporting\EventDefinitions\ConfigReportingEventDefinitionContributor;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Reporting\Providers\ReportingModuleServiceProvider;
use App\Modules\Reporting\Services\ReportingAttributionNormalizer;
use App\Modules\Reporting\Services\ReportingSessionResolver;
use App\Modules\Reporting\Validation\ReportingSetupValidationContributor;
use App\Providers\AppServiceProvider;
use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use App\Support\Reporting\Data\ReportingObservationData;
use App\Support\Reporting\Data\ReportingObservationResult;
use App\Support\Reporting\ReportingEventDefinitionRegistry;
use App\Support\Reporting\Services\NoopReportingObservationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ReportingObservationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_shared_recorder_defaults_to_noop_and_reporting_provider_overrides_it(): void
    {
        $container = new Container();

        (new AppServiceProvider($container))->register();

        $this->assertInstanceOf(
            NoopReportingObservationRecorder::class,
            $container->make(ReportingObservationRecorder::class),
        );

        (new ReportingModuleServiceProvider($container))->register();

        $this->assertInstanceOf(
            RecordReportingObservationAction::class,
            $container->make(ReportingObservationRecorder::class),
        );
    }

    public function test_config_event_definitions_are_versioned_and_strictly_registered(): void
    {
        $this->configurePageViewEvent();

        $definition = $this->definitionRegistry()->require('test.page_view', 1);

        $this->assertSame('test.page_view', $definition->key);
        $this->assertSame(1, $definition->version);
        $this->assertEquals(['webinar_registration'], $definition->surfaces);
        $this->assertTrue($definition->funnelEligible);
        $this->assertArrayHasKey('page_revision', $definition->properties);
    }

    public function test_observation_recording_is_private_normalized_and_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 04:30:00 UTC');
        $this->configurePageViewEvent();

        $eventId = (string) Str::uuid();
        $token = '0123456789abcdef0123456789abcdef';
        $input = $this->pageViewInput(
            eventId: $eventId,
            sessionToken: $token,
        );

        $first = $this->recorder()->record($input);
        $second = $this->recorder()->record($input);

        $this->assertSame(ReportingObservationResult::STATUS_RECORDED, $first->status);
        $this->assertSame(ReportingObservationResult::STATUS_DEDUPLICATED, $second->status);
        $this->assertSame($first->observationId, $second->observationId);
        $this->assertSame($first->sessionId, $second->sessionId);
        $this->assertDatabaseCount('reporting_observations', 1);
        $this->assertDatabaseCount('reporting_sessions', 1);

        $observation = ReportingObservation::query()->sole();
        $session = ReportingSession::query()->sole();

        $this->assertSame('/homebuyer-basics', $observation->path);
        $this->assertSame('search.example.test', $observation->referrer_host);
        $this->assertSame('search', $observation->utm_source);
        $this->assertSame('campaign-1', $observation->utm_campaign);
        $this->assertEquals(['page_revision' => 'revision-1'], $observation->properties);
        $this->assertSame(hash('sha256', $token), $session->token_hash);
        $this->assertNotSame($token, $session->token_hash);
        $this->assertSame('/homebuyer-basics', $session->landing_path);
        $this->assertSame('search.example.test', $session->referrer_host);

        $serializedObservation = json_encode($observation->getAttributes());
        $serializedSession = json_encode($session->getAttributes());

        $this->assertIsString($serializedObservation);
        $this->assertIsString($serializedSession);
        $this->assertStringNotContainsString('private@example.test', $serializedObservation);
        $this->assertStringNotContainsString('private@example.test', $serializedSession);
        $this->assertStringNotContainsString('?utm_', $observation->path);
        $this->assertStringNotContainsString('?q=', (string) $observation->referrer_host);
    }

    public function test_conflicting_uuid_replay_is_rejected_without_a_second_row(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 04:30:00 UTC');
        $this->configurePageViewEvent();

        $eventId = (string) Str::uuid();
        $recorder = $this->recorder();

        $recorder->record($this->pageViewInput(eventId: $eventId));

        try {
            $recorder->record($this->pageViewInput(
                eventId: $eventId,
                pageRevision: 'revision-2',
            ));

            $this->fail('Expected conflicting Reporting event replay to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('conflicting normalized content', $exception->getMessage());
        }

        $this->assertDatabaseCount('reporting_observations', 1);
    }

    public function test_session_resolution_supports_page_only_inactivity_and_absolute_expiry(): void
    {
        $resolver = new ReportingSessionResolver();
        $attribution = new NormalizedReportingAttribution(path: '/landing');
        $token = 'abcdef0123456789abcdef0123456789';
        $host = 'webinar.example.test';
        $surface = 'webinar_registration';
        $startedAt = CarbonImmutable::parse('2026-08-16 00:00:00 UTC');

        $this->assertNull($resolver->resolve(
            sessionToken: null,
            host: $host,
            surface: $surface,
            attribution: $attribution,
            receivedAt: $startedAt,
        ));

        $first = $resolver->resolve(
            sessionToken: $token,
            host: $host,
            surface: $surface,
            attribution: $attribution,
            receivedAt: $startedAt,
        );

        $withinInactivity = $resolver->resolve(
            sessionToken: $token,
            host: $host,
            surface: $surface,
            attribution: $attribution,
            receivedAt: $startedAt->addMinutes(29),
        );

        $afterInactivity = $resolver->resolve(
            sessionToken: $token,
            host: $host,
            surface: $surface,
            attribution: $attribution,
            receivedAt: $startedAt->addMinutes(60),
        );

        $this->assertNotNull($first);
        $this->assertSame($first->id, $withinInactivity?->id);
        $this->assertNotSame($first->id, $afterInactivity?->id);

        $absoluteToken = 'fedcba9876543210fedcba9876543210';
        $absoluteSession = ReportingSession::query()->create([
            'token_hash' => hash('sha256', $absoluteToken),
            'host' => $host,
            'surface' => $surface,
            'started_at' => $startedAt,
            'last_seen_at' => $startedAt->addMinutes(235),
            'absolute_expires_at' => $startedAt->addMinutes(240),
            'landing_path' => '/landing',
            'traffic_class' => 'unknown',
        ]);

        $afterAbsoluteExpiry = $resolver->resolve(
            sessionToken: $absoluteToken,
            host: $host,
            surface: $surface,
            attribution: $attribution,
            receivedAt: $startedAt->addMinutes(241),
        );

        $this->assertNotSame($absoluteSession->id, $afterAbsoluteExpiry?->id);
    }

    public function test_unknown_or_oversized_properties_are_rejected_before_persistence(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 04:30:00 UTC');
        $this->configurePageViewEvent();
        $recorder = $this->recorder();

        foreach ([
            ['unknown' => 'value'],
            ['page_revision' => str_repeat('x', 81)],
        ] as $properties) {
            try {
                $input = $this->pageViewInput(eventId: (string) Str::uuid());
                $input = new ReportingObservationData(
                    eventId: $input->eventId,
                    eventKey: $input->eventKey,
                    eventVersion: $input->eventVersion,
                    source: $input->source,
                    occurredAt: $input->occurredAt,
                    host: $input->host,
                    surface: $input->surface,
                    path: $input->path,
                    properties: $properties,
                    sessionToken: $input->sessionToken,
                    referrer: $input->referrer,
                    query: $input->query,
                    trafficClass: $input->trafficClass,
                );

                $recorder->record($input);
                $this->fail('Expected invalid Reporting properties to be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->assertDatabaseCount('reporting_observations', 0);
        $this->assertDatabaseCount('reporting_sessions', 0);
    }

    public function test_reporting_setup_validation_enforces_privacy_ceilings(): void
    {
        $this->configurePageViewEvent();

        $clean = iterator_to_array(
            (new ReportingSetupValidationContributor($this->definitionRegistry()))->findings(),
        );

        $this->assertEquals([], $clean);

        config()->set('reporting.session.inactivity_minutes', 31);

        $findings = iterator_to_array(
            (new ReportingSetupValidationContributor($this->definitionRegistry()))->findings(),
        );

        $this->assertContains(
            'reporting.session.inactivity_invalid',
            array_map(fn ($finding): string => $finding->code, $findings),
        );
    }

    private function configurePageViewEvent(): void
    {
        config()->set('reporting.events', [
            'test.page_view' => [
                1 => [
                    'surfaces' => ['webinar_registration'],
                    'session_mode' => 'expected',
                    'funnel_eligible' => true,
                    'properties' => [
                        'page_revision' => [
                            'type' => 'string',
                            'max_length' => 80,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function definitionRegistry(): ReportingEventDefinitionRegistry
    {
        return new ReportingEventDefinitionRegistry([
            new ConfigReportingEventDefinitionContributor(),
        ]);
    }

    private function recorder(): RecordReportingObservationAction
    {
        return new RecordReportingObservationAction(
            definitions: $this->definitionRegistry(),
            attributionNormalizer: new ReportingAttributionNormalizer(),
            sessionResolver: new ReportingSessionResolver(),
        );
    }

    private function pageViewInput(
        string $eventId,
        ?string $sessionToken = '0123456789abcdef0123456789abcdef',
        string $pageRevision = 'revision-1',
    ): ReportingObservationData {
        return new ReportingObservationData(
            eventId: $eventId,
            eventKey: 'test.page_view',
            eventVersion: 1,
            source: 'browser',
            occurredAt: CarbonImmutable::now('UTC'),
            host: 'webinar.example.test',
            surface: 'webinar-registration',
            path: 'https://webinar.example.test/homebuyer-basics?utm_source=search&utm_campaign=campaign-1&email=private@example.test#section',
            properties: [
                'page_revision' => $pageRevision,
            ],
            sessionToken: $sessionToken,
            referrer: 'https://search.example.test/results?q=private@example.test',
            query: [
                'utm_source' => 'search',
                'utm_campaign' => 'campaign-1',
                'email' => 'private@example.test',
                'arbitrary' => 'must-not-be-stored',
            ],
            trafficClass: 'unknown',
        );
    }
}