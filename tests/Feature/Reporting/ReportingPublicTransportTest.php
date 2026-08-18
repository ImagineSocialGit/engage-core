<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Reporting\Validation\ReportingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingPublicTransportTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    public function test_same_origin_browser_observation_is_server_classified_and_persisted_without_raw_request_identity(): void
    {
        $this->configureBrowserEvent('public.example.test');
        $eventId = (string) Str::uuid();

        $response = $this->postObservation(
            host: 'public.example.test',
            eventId: $eventId,
            sessionToken: '0123456789abcdef0123456789abcdef',
            query: [
                'utm_source' => 'newsletter',
                'utm_campaign' => 'august_webinar',
                'engage_platform' => 'meta',
                'engage_campaign_id' => 'cmp-100',
                'engage_group_id' => 'grp-200',
                'engage_creative_id' => 'ad-300',
                'engage_placement' => 'facebook_feed',
            ],
        );

        $response
            ->assertStatus(202)
            ->assertJson([
                'status' => 'recorded',
                'event_id' => $eventId,
            ]);

        $observation = ReportingObservation::query()
            ->where('event_id', $eventId)
            ->firstOrFail();
        $session = ReportingSession::query()->findOrFail($observation->reporting_session_id);

        $this->assertSame('browser', $observation->source);
        $this->assertSame('public.example.test', $observation->host);
        $this->assertSame('test_public', $observation->surface);
        $this->assertSame('/register', $observation->path);
        $this->assertSame('external.example', $observation->referrer_host);
        $this->assertSame('newsletter', $observation->utm_source);
        $this->assertSame('august_webinar', $observation->utm_campaign);
        $this->assertSame('meta', $observation->external_platform);
        $this->assertSame('cmp-100', $observation->external_campaign_id);
        $this->assertSame('grp-200', $observation->external_group_id);
        $this->assertSame('ad-300', $observation->external_creative_id);
        $this->assertSame('facebook_feed', $observation->external_placement);
        $this->assertSame('likely_human', $observation->traffic_class);
        $this->assertSame('browser_request_signals', $observation->classifier_key);
        $this->assertSame(2, $observation->classifier_version);
        $this->assertEquals([
            'browser_family_recognized',
            'same_origin_fetch_metadata',
        ], $observation->classification_reasons);
        $this->assertSame('desktop', $observation->device_class);
        $this->assertSame('Chrome', $observation->browser_family);
        $this->assertSame('Windows', $observation->os_family);
        $this->assertEquals(['page_revision' => 'revision-1'], $observation->properties);

        $persisted = json_encode([
            $observation->toArray(),
            $session->toArray(),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::BROWSER_UA, $persisted);
        $this->assertStringNotContainsString('127.0.0.1', $persisted);
    }

    public function test_missing_browser_session_token_falls_back_to_page_only_observation(): void
    {
        $this->configureBrowserEvent('public.example.test');
        $eventId = (string) Str::uuid();

        $this->postObservation(
            host: 'public.example.test',
            eventId: $eventId,
            sessionToken: null,
        )->assertStatus(202);

        $observation = ReportingObservation::query()
            ->where('event_id', $eventId)
            ->firstOrFail();

        $this->assertNull($observation->reporting_session_id);
        $this->assertSame(0, ReportingSession::query()->count());
    }

    public function test_known_automation_user_agent_is_not_counted_as_likely_human(): void
    {
        $this->configureBrowserEvent('public.example.test');
        $eventId = (string) Str::uuid();

        $this->postObservation(
            host: 'public.example.test',
            eventId: $eventId,
            sessionToken: '0123456789abcdef0123456789abcdef',
            userAgent: 'Mozilla/5.0 HeadlessChrome/151.0.0.0',
        )->assertStatus(202);

        $observation = ReportingObservation::query()
            ->where('event_id', $eventId)
            ->firstOrFail();

        $this->assertSame('likely_automated', $observation->traffic_class);
        $this->assertSame('browser_request_signals', $observation->classifier_key);
        $this->assertSame(2, $observation->classifier_version);
        $this->assertEquals(['automation_user_agent'], $observation->classification_reasons);
    }

    public function test_recognized_browser_without_fetch_metadata_is_likely_human_under_classifier_v2(): void
    {
        $this->configureBrowserEvent('public.example.test');

        foreach ([
            'chrome' => self::BROWSER_UA,
            'firefox' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:141.0) Gecko/20100101 Firefox/141.0',
            'safari' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
        ] as $browser => $userAgent) {
            $eventId = (string) Str::uuid();

            $this->postObservation(
                host: 'public.example.test',
                eventId: $eventId,
                sessionToken: '0123456789abcdef0123456789abcdef',
                userAgent: $userAgent,
                fetchSite: null,
            )->assertStatus(202);

            $observation = ReportingObservation::query()
                ->where('event_id', $eventId)
                ->firstOrFail();

            $this->assertSame(
                'likely_human',
                $observation->traffic_class,
                "Recognized {$browser} traffic without Fetch Metadata should remain in the likely-human bucket.",
            );
            $this->assertSame('browser_request_signals', $observation->classifier_key);
            $this->assertSame(2, $observation->classifier_version);
            $this->assertEquals([
                'browser_family_recognized',
                'fetch_metadata_missing',
            ], $observation->classification_reasons);
        }
    }

    public function test_missing_or_unrecognized_user_agent_remains_unknown_under_classifier_v2(): void
    {
        $this->configureBrowserEvent('public.example.test');

        foreach ([
            'missing' => [
                'user_agent' => '',
                'reasons' => ['user_agent_missing'],
            ],
            'unrecognized' => [
                'user_agent' => 'ExampleClient/1.0',
                'reasons' => ['user_agent_unrecognized'],
            ],
        ] as $case) {
            $eventId = (string) Str::uuid();

            $this->postObservation(
                host: 'public.example.test',
                eventId: $eventId,
                sessionToken: '0123456789abcdef0123456789abcdef',
                userAgent: $case['user_agent'],
                fetchSite: null,
            )->assertStatus(202);

            $observation = ReportingObservation::query()
                ->where('event_id', $eventId)
                ->firstOrFail();

            $this->assertSame('unknown', $observation->traffic_class);
            $this->assertSame('browser_request_signals', $observation->classifier_key);
            $this->assertSame(2, $observation->classifier_version);
            $this->assertEquals($case['reasons'], $observation->classification_reasons);
        }
    }

    public function test_public_transport_rejects_cross_origin_or_wrong_host_requests_without_persistence(): void
    {
        $this->configureBrowserEvent('public.example.test');

        $this->postObservation(
            host: 'public.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
            origin: 'https://evil.example',
        )->assertNotFound();

        $this->postObservation(
            host: 'other.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )->assertNotFound();

        $this->postObservation(
            host: 'public.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
            fetchSite: 'cross-site',
        )->assertNotFound();

        $this->assertSame(0, ReportingObservation::query()->count());
    }

    public function test_public_transport_rejects_client_owned_classification_and_unknown_attribution_keys(): void
    {
        $this->configureBrowserEvent('public.example.test');
        $payload = $this->payload(
            eventId: (string) Str::uuid(),
            sessionToken: null,
        );
        $payload['traffic_class'] = 'likely_human';

        $this->withHeaders([
            ...$this->browserHeaders('http://public.example.test'),
            'Host' => 'public.example.test',
        ])
            ->postJson('http://public.example.test/_reporting/observations', $payload)
            ->assertStatus(422)
            ->assertJson([
                'status' => 'rejected',
                'code' => 'invalid_payload',
            ]);

        $payload = $this->payload(
            eventId: (string) Str::uuid(),
            sessionToken: null,
            query: ['email' => 'person@example.test'],
        );

        $this->withHeaders([
            ...$this->browserHeaders('http://public.example.test'),
            'Host' => 'public.example.test',
        ])
            ->postJson('http://public.example.test/_reporting/observations', $payload)
            ->assertStatus(422)
            ->assertJson([
                'status' => 'rejected',
                'code' => 'invalid_observation',
            ]);

        $this->assertSame(0, ReportingObservation::query()->count());
    }

    public function test_public_transport_is_scoped_by_module_and_browser_collection_enablement(): void
    {
        $this->configureBrowserEvent('public.example.test');
        config(['reporting.collection.browser_enabled' => false]);

        $this->postObservation(
            host: 'public.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )->assertNotFound();

        config(['reporting.collection.browser_enabled' => true]);
        config([
            'modules.enabled' => array_values(array_diff(
                config('modules.enabled', []),
                ['reporting'],
            )),
        ]);

        $this->postObservation(
            host: 'public.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )->assertNotFound();

        $this->assertSame(0, ReportingObservation::query()->count());
    }

    public function test_public_transport_applies_the_configured_host_scoped_ip_rate_limit(): void
    {
        $this->configureBrowserEvent('rate.example.test');
        config([
            'reporting.ingestion.rate_limit_per_ip_per_minute' => 1,
            'reporting.ingestion.rate_limit_per_session_per_minute' => 90,
        ]);

        $this->postObservation(
            host: 'rate.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )->assertStatus(202);

        $this->postObservation(
            host: 'rate.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )->assertStatus(429);

        $this->assertSame(1, ReportingObservation::query()->count());
    }

    public function test_invalid_runtime_classifier_configuration_fails_closed_without_recording(): void
    {
        $this->configureBrowserEvent('public.example.test');
        config(['reporting.classification.browser_classifier' => 'unsupported']);

        $this->postObservation(
            host: 'public.example.test',
            eventId: (string) Str::uuid(),
            sessionToken: null,
        )
            ->assertStatus(503)
            ->assertJson([
                'status' => 'rejected',
                'code' => 'not_available',
            ]);

        $this->assertSame(0, ReportingObservation::query()->count());
    }

    public function test_setup_validation_rejects_unsupported_public_transport_configuration(): void
    {
        config([
            'reporting.collection.browser_enabled' => 'yes',
            'reporting.classification.browser_classifier' => 'custom',
            'reporting.ingestion.rate_limit_per_ip_per_minute' => 0,
        ]);

        $codes = collect(iterator_to_array(
            $this->app->make(ReportingSetupValidationContributor::class)->findings(),
            false,
        ))
            ->pluck('code')
            ->all();

        $this->assertContains('reporting.collection.browser_enabled_invalid', $codes);
        $this->assertContains('reporting.classification.browser_classifier_invalid', $codes);
        $this->assertContains('reporting.ingestion.rate_limit_per_ip_per_minute_invalid', $codes);
    }

    private function configureBrowserEvent(string $host): void
    {
        config([
            'reporting.collection.browser_enabled' => true,
            'reporting.classification.browser_classifier' => 'request_signals_v2',
            'reporting.ingestion.rate_limit_per_ip_per_minute' => 120,
            'reporting.ingestion.rate_limit_per_session_per_minute' => 90,
            'reporting.events' => [
                'test.page_view' => [
                    1 => [
                        'surfaces' => ['test_public'],
                        'browser_hosts' => [$host],
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
            ],
        ]);
    }

    private function postObservation(
        string $host,
        string $eventId,
        ?string $sessionToken,
        ?string $origin = null,
        string $userAgent = self::BROWSER_UA,
        ?string $fetchSite = 'same-origin',
        array $query = [],
    ) {
        $origin ??= 'http://'.$host;
        $headers = $this->browserHeaders(
            origin: $origin,
            userAgent: $userAgent,
            fetchSite: $fetchSite,
        );

        $headers['Host'] = $host;

        return $this->withHeaders($headers)
            ->postJson(
                'http://'.$host.'/_reporting/observations',
                $this->payload(
                    eventId: $eventId,
                    sessionToken: $sessionToken,
                    query: $query,
                ),
            );
    }

    /** @return array<string, string> */
    private function browserHeaders(
        string $origin,
        string $userAgent = self::BROWSER_UA,
        ?string $fetchSite = 'same-origin',
    ): array {
        return array_filter([
            'Origin' => $origin,
            'User-Agent' => $userAgent,
            'Sec-Fetch-Site' => $fetchSite,
        ], fn (?string $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function payload(
        string $eventId,
        ?string $sessionToken,
        array $query = [],
    ): array {
        return [
            'event_id' => $eventId,
            'event_key' => 'test.page_view',
            'event_version' => 1,
            'occurred_at' => now('UTC')->toISOString(),
            'surface' => 'test_public',
            'path' => '/register',
            'properties' => [
                'page_revision' => 'revision-1',
            ],
            'session_token' => $sessionToken,
            'referrer_host' => 'external.example',
            'query' => $query,
        ];
    }
}