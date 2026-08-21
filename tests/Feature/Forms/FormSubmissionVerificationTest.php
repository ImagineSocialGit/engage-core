<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Validation\FormsSetupValidationContributor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FormSubmissionVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'engage_sites';

    private const SECRET = 'test-external-forms-secret-with-more-than-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-08-20T20:00:00+00:00');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_signed_external_verification_is_normalized_and_persisted_as_forms_owned_evidence(): void
    {
        $this->publishedArtistForm($this->requiredVerificationPolicy());

        $payload = $this->payload(
            externalId: '4f35d991-d1dc-4fa0-8d6f-11efeb8fe20a',
            verification: [
                'provider' => 'TURNSTILE',
                'outcome' => 'PASSED',
                'verified_at' => '2026-08-20T19:59:30Z',
                'hostname' => 'Artist.Example.Com',
                'action' => 'artist_updates',
            ],
        );

        $this->signedRequest($payload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);

        $submission = FormSubmission::query()->sole();
        $evidence = $submission->meta['_forms']['verification'];

        $this->assertEquals([
            'version' => 1,
            'provider' => 'turnstile',
            'outcome' => 'passed',
            'verified_at' => '2026-08-20T19:59:30+00:00',
            'hostname' => 'artist.example.com',
            'action' => 'artist_updates',
            'authenticated_client_id' => self::CLIENT_ID,
        ], $evidence);
        $this->assertSame(1, Contact::query()->count());

        $serialized = json_encode(
            $submission->meta,
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString('token', $serialized);
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString(
            'cf-turnstile-response',
            $serialized,
        );
    }

    public function test_required_verification_rejects_missing_wrong_provider_wrong_action_stale_and_future_evidence(): void
    {
        $this->publishedArtistForm($this->requiredVerificationPolicy());

        $cases = [
            [
                'verification' => null,
                'message' => 'This form requires server-authored human-verification evidence.',
            ],
            [
                'verification' => [
                    'provider' => 'other_provider',
                    'outcome' => 'passed',
                    'verified_at' => '2026-08-20T19:59:30+00:00',
                    'hostname' => 'artist.example.com',
                    'action' => 'artist_updates',
                ],
                'message' => 'Verification provider [other_provider] is not accepted for this form.',
            ],
            [
                'verification' => [
                    'provider' => 'turnstile',
                    'outcome' => 'passed',
                    'verified_at' => '2026-08-20T19:59:30+00:00',
                    'hostname' => 'artist.example.com',
                    'action' => 'different_action',
                ],
                'message' => 'Verification action does not match this form.',
            ],
            [
                'verification' => [
                    'provider' => 'turnstile',
                    'outcome' => 'passed',
                    'verified_at' => '2026-08-20T19:54:59+00:00',
                    'hostname' => 'artist.example.com',
                    'action' => 'artist_updates',
                ],
                'message' => 'Verification evidence is too old for this form.',
            ],
            [
                'verification' => [
                    'provider' => 'turnstile',
                    'outcome' => 'passed',
                    'verified_at' => '2026-08-20T20:01:01+00:00',
                    'hostname' => 'artist.example.com',
                    'action' => 'artist_updates',
                ],
                'message' => 'Verification evidence is timestamped too far in the future.',
            ],
        ];

        foreach ($cases as $index => $case) {
            $payload = $this->payload(
                externalId: sprintf(
                    '10000000-0000-4000-8000-%012d',
                    $index + 1,
                ),
                verification: $case['verification'],
            );

            $this->signedRequest($payload)
                ->assertUnprocessable()
                ->assertJsonPath(
                    'error.details.errors._verification.0',
                    $case['message'],
                );
        }

        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_verification_envelope_rejects_unknown_keys_including_raw_provider_tokens(): void
    {
        $this->publishedArtistForm($this->requiredVerificationPolicy());

        $payload = $this->payload(
            externalId: '3b72b301-9a7f-44b0-8c33-ab3834a4f944',
            verification: [
                'provider' => 'turnstile',
                'outcome' => 'passed',
                'verified_at' => '2026-08-20T19:59:30+00:00',
                'hostname' => 'artist.example.com',
                'action' => 'artist_updates',
                'token' => 'must-not-cross-core-boundary',
            ],
        );

        $this->signedRequest($payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.errors.verification.0',
                'Unknown verification key(s): token.',
            );

        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_verification_evidence_is_part_of_the_durable_logical_request_identity(): void
    {
        $this->publishedArtistForm($this->requiredVerificationPolicy());

        $externalId = '7915da8f-705a-4f46-9c20-8d68b12c93b0';
        $first = $this->payload(
            externalId: $externalId,
            verification: [
                'provider' => 'turnstile',
                'outcome' => 'passed',
                'verified_at' => '2026-08-20T19:59:30+00:00',
                'hostname' => 'artist.example.com',
                'action' => 'artist_updates',
            ],
        );
        $changedEvidence = $first;
        $changedEvidence['verification']['verified_at'] =
            '2026-08-20T19:59:31+00:00';

        $this->signedRequest(
            payload: $first,
            nonce: '0ed85c5c-5833-4f00-b516-3b47d1c52b78',
        )->assertCreated();

        $this->signedRequest(
            payload: $changedEvidence,
            nonce: 'de047fe4-a738-4cd2-b911-edce53a8d984',
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'external_id_conflict',
            );

        $this->assertSame(1, FormSubmission::query()->count());
    }

    public function test_legacy_external_fingerprint_without_verification_remains_replay_compatible(): void
    {
        [$definition, $version] = $this->publishedArtistForm();
        $externalId = '891a3920-a3bf-4046-a41f-4170d8d2ff5d';
        $payload = $this->payload(
            externalId: $externalId,
            verification: null,
        );
        $logicalRequest = [
            'form_key' => 'artist_updates',
            'source' => 'engage_sites',
            'values' => [
                'email' => 'fan@example.com',
            ],
            'meta' => [
                'consent' => [
                    'disclosure_key' => 'artist_updates_v1',
                ],
            ],
        ];
        $fingerprint = hash(
            'sha256',
            json_encode(
                $logicalRequest,
                JSON_THROW_ON_ERROR
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );

        $submission = FormSubmission::query()->create([
            'form_definition_id' => $definition->getKey(),
            'form_version_id' => $version->getKey(),
            'contact_id' => null,
            'status' => FormSubmission::STATUS_SUBMITTED,
            'review_status' => FormSubmission::REVIEW_STATUS_PENDING,
            'submitted_at' => now(),
            'source' => 'engage_sites',
            'provider' => 'engage_sites',
            'external_id' => $externalId,
            'payload' => $payload['values'],
            'raw_payload' => $payload['values'],
            'meta' => [
                ...$payload['meta'],
                '_forms' => [
                    'runtime_version' => 1,
                    'idempotency_fingerprint' => $fingerprint,
                ],
            ],
        ]);

        $this->signedRequest($payload)
            ->assertOk()
            ->assertJsonPath(
                'data.submission_id',
                (int) $submission->getKey(),
            )
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, FormSubmission::query()->count());
    }

    public function test_setup_validation_reports_invalid_required_verification_policy(): void
    {
        [, $version] = $this->publishedArtistForm([
            'required' => true,
            'providers' => [],
            'max_age_seconds' => 300,
            'action' => 'artist_updates',
            'require_hostname' => true,
        ]);

        $finding = collect(
            app(FormsSetupValidationContributor::class)->findings(),
        )->firstWhere(
            'code',
            'forms.runtime.submission_verification_invalid',
        );

        $this->assertNotNull($finding);
        $this->assertSame(
            "form_versions.{$version->getKey()}.settings.submission.verification",
            $finding->path,
        );
    }

    /**
     * @param  array<string, mixed>|null  $verificationPolicy
     * @return array{FormDefinition, FormVersion}
     */
    private function publishedArtistForm(
        ?array $verificationPolicy = null,
    ): array {
        $definition = FormDefinition::factory()
            ->active()
            ->public()
            ->create([
                'key' => 'artist_updates',
                'name' => 'Artist Updates',
            ]);

        $submissionSettings = [
            'contact' => [
                'fields' => [
                    'email' => 'email',
                ],
                'source' => 'engage_sites',
                'subsource' => 'artist_updates',
            ],
        ];

        if ($verificationPolicy !== null) {
            $submissionSettings['verification'] = $verificationPolicy;
        }

        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Artist Updates',
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [[
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ]],
                ]],
            ],
            'settings' => [
                'submission' => $submissionSettings,
            ],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return [$definition->refresh(), $version->refresh()];
    }

    /**
     * @return array{
     *     required: bool,
     *     providers: array<int, string>,
     *     max_age_seconds: int,
     *     action: string,
     *     require_hostname: bool
     * }
     */
    private function requiredVerificationPolicy(): array
    {
        return [
            'required' => true,
            'providers' => ['turnstile'],
            'max_age_seconds' => 300,
            'action' => 'artist_updates',
            'require_hostname' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $verification
     * @return array<string, mixed>
     */
    private function payload(
        string $externalId,
        ?array $verification,
    ): array {
        $payload = [
            'external_id' => $externalId,
            'values' => [
                'email' => 'fan@example.com',
            ],
            'meta' => [
                'consent' => [
                    'disclosure_key' => 'artist_updates_v1',
                ],
            ],
        ];

        if ($verification !== null) {
            $payload['verification'] = $verification;
        }

        return $payload;
    }

    private function signedRequest(
        array $payload,
        ?string $nonce = null,
    ) {
        $nonce ??= fake()->uuid();
        $timestamp = time();
        $path = '/forms/artist_updates/submissions';
        $host = 'webhooks.'.config('app.root_domain');
        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $signature = 'v1='.hash_hmac('sha256', implode("\n", [
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
                'HTTP_X_ENGAGE_CLIENT' => self::CLIENT_ID,
                'HTTP_X_ENGAGE_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ENGAGE_NONCE' => $nonce,
                'HTTP_X_ENGAGE_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }
}