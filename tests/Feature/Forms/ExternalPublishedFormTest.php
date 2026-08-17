<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExternalPublishedFormTest extends TestCase
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

    public function test_authenticated_read_returns_the_exact_current_presentation_safe_published_form(): void
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
        ]);
        FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Old Artist Updates',
            'schema' => $this->schema('old_email'),
        ]);
        $current = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 2,
            'name' => 'Current Artist Updates',
            'description' => 'Choose the artist updates you want to receive.',
            'schema' => $this->schema('email'),
            'rules' => [
                'email' => ['email'],
            ],
            'layout' => [
                'variant' => 'compact',
            ],
            'settings' => [
                'public' => [
                    'success_message_key' => 'artist_updates_saved',
                    'consent' => [
                        'disclosure_key' => 'artist_updates_v1',
                    ],
                ],
                'submission' => [
                    'contact' => [
                        'fields' => [
                            'email' => 'email',
                        ],
                        'source' => 'engage_sites',
                        'subsource' => 'artist_updates',
                    ],
                    'tags' => [[
                        'field' => 'interests',
                        'values' => [
                            'music' => 'interest:music',
                        ],
                    ]],
                ],
                'server_only' => 'must-not-leak',
            ],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $current->getKey(),
        ])->save();

        $response = $this->signedGet();

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID')
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.definition_id', (int) $definition->getKey())
            ->assertJsonPath('data.version_id', (int) $current->getKey())
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.key', 'artist_updates')
            ->assertJsonPath('data.name', 'Current Artist Updates')
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.fields.0.key', 'email')
            ->assertJsonPath('data.fields.0.required', true);

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertIsString($cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $this->assertEquals(
            ['email' => ['email']],
            $response->json('data.rules'),
        );
        $this->assertEquals(
            ['variant' => 'compact'],
            $response->json('data.layout'),
        );
        $this->assertEquals([
            'success_message_key' => 'artist_updates_saved',
            'consent' => [
                'disclosure_key' => 'artist_updates_v1',
            ],
        ], $response->json('data.settings'));
        $response
            ->assertDontSee('interest:music')
            ->assertDontSee('must-not-leak');
    }

    public function test_private_inactive_and_unpublished_forms_fail_closed(): void
    {
        $this->form(
            key: 'private_form',
            isPublic: false,
        );
        $this->form(
            key: 'inactive_form',
            definitionStatus: FormDefinition::STATUS_DRAFT,
        );
        $this->form(
            key: 'unpublished_form',
            versionStatus: FormVersion::STATUS_DRAFT,
        );
        config()->set(
            'forms.external_intake.clients.'.self::CLIENT_ID.'.allowed_forms',
            ['private_form', 'inactive_form', 'unpublished_form'],
        );

        foreach (['private_form', 'inactive_form', 'unpublished_form'] as $formKey) {
            $this->signedGet($formKey)
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'form_unavailable')
                ->assertJsonMissingPath('data');
        }
    }

    public function test_authentication_allowlist_timestamp_and_nonce_replay_fail_closed(): void
    {
        $this->form('artist_updates');

        $this->signedGet(signature: 'v1='.str_repeat('0', 64))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'authentication_failed');

        config()->set(
            'forms.external_intake.clients.'.self::CLIENT_ID.'.allowed_forms',
            ['another_form'],
        );
        $this->signedGet()
            ->assertForbidden()
            ->assertJsonPath('error.code', 'form_not_allowed');

        config()->set(
            'forms.external_intake.clients.'.self::CLIENT_ID.'.allowed_forms',
            ['artist_updates'],
        );
        $this->signedGet(timestamp: time() - 301)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'authentication_failed');

        $nonce = '49aef7dc-a3fb-49ae-af27-0b0cfab0ab65';
        $timestamp = time();
        $this->signedGet(nonce: $nonce, timestamp: $timestamp)->assertOk();
        $this->signedGet(nonce: $nonce, timestamp: $timestamp)
            ->assertConflict()
            ->assertJsonPath('error.code', 'request_replayed');
    }

    /**
     * @return array{FormDefinition, FormVersion}
     */
    private function form(
        string $key,
        bool $isPublic = true,
        string $definitionStatus = FormDefinition::STATUS_ACTIVE,
        string $versionStatus = FormVersion::STATUS_PUBLISHED,
    ): array {
        $definition = FormDefinition::factory()->create([
            'key' => $key,
            'name' => str_replace('_', ' ', $key),
            'status' => $definitionStatus,
            'is_public' => $isPublic,
        ]);
        $version = FormVersion::factory()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => $versionStatus,
            'published_at' => $versionStatus === FormVersion::STATUS_PUBLISHED
                ? now()
                : null,
            'schema' => $this->schema('email'),
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return [$definition->refresh(), $version->refresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $emailKey): array
    {
        return [
            'sections' => [[
                'key' => 'contact',
                'label' => 'Contact',
                'fields' => [
                    [
                        'key' => $emailKey,
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
                            ['value' => 'tour', 'label' => 'Tour'],
                        ],
                    ],
                ],
            ]],
        ];
    }

    private function signedGet(
        string $form = 'artist_updates',
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $signature = null,
    ) {
        $nonce ??= fake()->uuid();
        $timestamp ??= time();
        $path = "/forms/{$form}";
        $body = '';
        $host = 'webhooks.'.config('app.root_domain');
        $signature ??= 'v1='.hash_hmac('sha256', implode("\n", [
            'v1',
            self::CLIENT_ID,
            (string) $timestamp,
            strtolower($nonce),
            'GET',
            $path,
            hash('sha256', $body),
        ]), self::SECRET);

        return $this->call(
            method: 'GET',
            uri: 'http://'.$host.$path,
            server: [
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