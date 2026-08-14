<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExternalFormIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'engage_sites';
    private const SECRET = 'test-external-forms-secret-with-more-than-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('modules.enabled', ['forms']);
        config()->set('forms.external_intake', [
            'enabled' => true,
            'max_body_bytes' => 262144,
            'max_timestamp_drift_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'unauthenticated_rate_limit_per_minute' => 120,
            'client_rate_limit_per_minute' => 60,
            'clients' => [
                self::CLIENT_ID => [
                    'secret' => self::SECRET,
                    'source' => 'engage_sites',
                    'provider' => 'engage_sites',
                    'allowed_forms' => ['artist_updates'],
                ],
            ],
        ]);
    }

    public function test_signed_external_request_creates_a_pinned_submission_with_server_owned_attribution(): void
    {
        [, $version] = $this->publishedArtistForm();
        $externalId = 'ad918706-38b4-449c-8675-311c9a85bf09';

        $response = $this->signedRequest($this->payload($externalId));

        $response
            ->assertCreated()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('data.form_key', 'artist_updates')
            ->assertJsonPath('data.form_version.id', (int) $version->getKey())
            ->assertJsonPath('data.form_version.number', 1)
            ->assertJsonPath('data.status', FormSubmission::STATUS_SUBMITTED)
            ->assertJsonPath('data.replayed', false);

        $submission = FormSubmission::query()->sole();
        $contact = Contact::query()->sole();

        $this->assertSame((int) $version->getKey(), $submission->form_version_id);
        $this->assertSame('engage_sites', $submission->source);
        $this->assertSame('engage_sites', $submission->provider);
        $this->assertSame($externalId, $submission->external_id);
        $this->assertSame('203.0.113.42', $submission->ip_address);
        $this->assertSame('Artist site visitor browser', $submission->user_agent);
        $this->assertSame(' FAN@EXAMPLE.COM ', $submission->raw_payload['email']);
        $this->assertSame('fan@example.com', $submission->payload['email']);
        $this->assertSame('artist_updates_v1', $submission->meta['consent']['disclosure_key']);
        $this->assertSame('fan@example.com', $contact->email);
        $this->assertEqualsCanonicalizing([
            'interest:music',
            'interest:vip',
        ], $contact->tags()->pluck('tag')->all());
    }

    public function test_transport_retry_uses_a_fresh_nonce_and_replays_the_durable_external_identity(): void
    {
        $this->publishedArtistForm();
        $payload = $this->payload('60e40f93-907d-49d6-ad85-1835577102ec');
        $retryPayload = $payload;
        $retryPayload['provenance']['ip_address'] = '203.0.113.43';
        $retryPayload['provenance']['user_agent'] = 'Artist site visitor browser after retry';

        $first = $this->signedRequest(
            payload: $payload,
            nonce: '81433e9f-55f5-42d0-9334-90b72fd886a6',
        );
        $replay = $this->signedRequest(
            payload: $retryPayload,
            nonce: 'bc7cb1d6-7ab2-4e8f-8cfb-62390706c318',
        );

        $first->assertCreated();
        $replay
            ->assertOk()
            ->assertJsonPath('data.submission_id', $first->json('data.submission_id'))
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, ContactTag::query()->count());
        $this->assertSame(
            '203.0.113.42',
            FormSubmission::query()->sole()->ip_address,
        );
    }

    public function test_missing_visitor_provenance_does_not_mislabel_the_authenticated_peer(): void
    {
        $this->publishedArtistForm();
        $payload = $this->payload('30e85428-b1f4-4c0e-8ca2-7cfb1a3dd31f');
        unset($payload['provenance']);

        $this->signedRequest($payload)->assertCreated();

        $submission = FormSubmission::query()->sole();

        $this->assertNull($submission->ip_address);
        $this->assertNull($submission->user_agent);
    }

    public function test_conflicting_external_identity_is_rejected_without_duplicate_side_effects(): void
    {
        $this->publishedArtistForm();
        $externalId = '9fc7dfdf-d43b-4e3b-bb6a-16244be9ddeb';
        $this->signedRequest($this->payload($externalId))->assertCreated();
        $conflict = $this->payload($externalId);
        $conflict['values']['first_name'] = 'Different';

        $this->signedRequest($conflict)
            ->assertConflict()
            ->assertJsonPath('error.code', 'external_id_conflict');

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, ContactTag::query()->count());
    }

    public function test_signature_client_form_allowlist_and_request_nonce_fail_closed(): void
    {
        $this->publishedArtistForm();
        $payload = $this->payload('b3fc978d-b194-4c0c-a9f2-e83a158f0dc5');
        $nonce = '68a77fc4-f1de-4128-a849-fd8a7595fc65';

        $this->signedRequest($payload, signature: 'v1='.str_repeat('0', 64))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'authentication_failed');

        config()->set(
            'forms.external_intake.clients.'.self::CLIENT_ID.'.allowed_forms',
            ['another_form'],
        );
        $this->signedRequest($payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'form_not_allowed');

        config()->set(
            'forms.external_intake.clients.'.self::CLIENT_ID.'.allowed_forms',
            ['artist_updates'],
        );
        $this->signedRequest($payload, nonce: $nonce)->assertCreated();
        $this->signedRequest($payload, nonce: $nonce)
            ->assertConflict()
            ->assertJsonPath('error.code', 'request_replayed');
    }

    public function test_invalid_json_envelope_and_form_values_return_stable_errors_without_writes(): void
    {
        $this->publishedArtistForm();

        $this->signedBody('{invalid')
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'invalid_json');

        config()->set('forms.external_intake.max_body_bytes', 1024);
        $oversized = $this->payload('7a23acfe-8e10-469c-aabc-1817c0572a3d');
        $oversized['values']['first_name'] = str_repeat('x', 1024);
        $this->signedRequest($oversized)
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large');
        config()->set('forms.external_intake.max_body_bytes', 262144);

        $payload = $this->payload('dd4d41f6-2152-4924-992b-0e1f60827289');
        $payload['unexpected'] = true;
        $this->signedRequest($payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['_request']]]]);

        $invalidProvenance = $this->payload('de001385-7ef8-4394-a0ae-53de3725bbb4');
        $invalidProvenance['provenance'] = [
            'ip_address' => 'not-an-ip-address',
            'unexpected' => true,
        ];
        $this->signedRequest($invalidProvenance)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['errors' => [
                'provenance',
                'provenance.ip_address',
            ]]]]);

        unset($payload['unexpected']);
        $payload['values']['email'] = 'not-an-email';
        $payload['values']['browser_tag'] = 'interest:anything';
        $this->signedRequest($payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['errors' => [
                '_submission',
                'email',
            ]]]]);

        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_stale_requests_and_anonymous_rate_exhaustion_are_rejected(): void
    {
        $this->publishedArtistForm();
        $payload = $this->payload('313b0e30-ed07-4fe4-adb0-79da00a996dd');

        $this->signedRequest($payload, timestamp: time() - 301)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'authentication_failed');

        Cache::flush();
        config()->set(
            'forms.external_intake.unauthenticated_rate_limit_per_minute',
            1,
        );

        $this->signedRequest($payload, signature: 'v1='.str_repeat('0', 64))
            ->assertUnauthorized();
        $this->signedRequest($payload, signature: 'v1='.str_repeat('0', 64))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited');
    }

    /**
     * @return array{FormDefinition, FormVersion}
     */
    private function publishedArtistForm(): array
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Artist Updates',
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [
                        [
                            'key' => 'first_name',
                            'label' => 'First name',
                            'type' => 'text',
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                        ],
                        [
                            'key' => 'interests',
                            'label' => 'Interests',
                            'type' => 'checkboxes',
                            'options' => [
                                ['value' => 'music', 'label' => 'Music'],
                                ['value' => 'vip', 'label' => 'VIP'],
                            ],
                        ],
                    ],
                ]],
            ],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => [
                            'email' => 'email',
                            'first_name' => 'first_name',
                        ],
                        'source' => 'engage_sites',
                        'subsource' => 'artist_updates',
                    ],
                    'tags' => [[
                        'field' => 'interests',
                        'values' => [
                            'music' => 'interest:music',
                            'vip' => 'interest:vip',
                        ],
                    ]],
                ],
            ],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return [$definition->refresh(), $version->refresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'values' => [
                'first_name' => 'Fan',
                'email' => ' FAN@EXAMPLE.COM ',
                'interests' => ['music', 'vip'],
            ],
            'meta' => [
                'consent' => [
                    'disclosure_key' => 'artist_updates_v1',
                ],
            ],
            'provenance' => [
                'ip_address' => '203.0.113.42',
                'user_agent' => 'Artist site visitor browser',
            ],
        ];
    }

    private function signedRequest(
        array $payload,
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $signature = null,
    ) {
        return $this->signedBody(
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            nonce: $nonce,
            timestamp: $timestamp,
            signature: $signature,
        );
    }

    private function signedBody(
        string $body,
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $signature = null,
    ) {
        $nonce ??= fake()->uuid();
        $timestamp ??= time();
        $path = '/forms/artist_updates/submissions';
        $host = 'webhooks.'.config('app.root_domain');
        $signature ??= 'v1='.hash_hmac('sha256', implode("\n", [
            'v1',
            self::CLIENT_ID,
            (string) $timestamp,
            strtolower($nonce),
            'POST',
            $path,
            hash('sha256', $body),
        ]), self::SECRET);

        return $this->call(
            method: 'POST',
            uri: 'http://'.$host.$path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Engage Sites intake test',
                'HTTP_X_ENGAGE_CLIENT' => self::CLIENT_ID,
                'HTTP_X_ENGAGE_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ENGAGE_NONCE' => $nonce,
                'HTTP_X_ENGAGE_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }
}