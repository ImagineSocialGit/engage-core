<?php

namespace Tests\Feature\Forms;

use App\Models\User;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormsSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms', 'messaging']);
        config()->set('messaging.consent_domains', [
            'marketing' => [
                'topic' => 'marketing communications',
                'scopes' => [],
                'scope_prefixes' => [],
                'opt_in' => [],
            ],
        ]);
        config()->set('messaging.consent.channel_purpose_domains', [
            'email' => [
                'marketing' => 'marketing',
            ],
        ]);
        config()->set('forms.external_intake', [
            'enabled' => true,
            'max_body_bytes' => 262144,
            'max_timestamp_drift_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'unauthenticated_rate_limit_per_minute' => 120,
            'client_rate_limit_per_minute' => 60,
            'clients' => [
                'engage_sites' => [
                    'secret' => 'test-external-forms-secret-with-more-than-32-bytes',
                    'source' => 'engage_sites',
                    'provider' => 'engage_sites',
                    'allowed_forms' => ['artist_updates'],
                    'domains' => [
                        'example.com',
                        'updates.example.com',
                    ],
                ],
            ],
        ]);

        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
    }


    public function test_forms_navigation_is_present_only_when_the_module_is_enabled(): void
    {
        $enabledItems = collect(app(ModuleManager::class)->navigationItems());

        $this->assertSame(
            'crm.forms.index',
            $enabledItems->firstWhere('module', 'forms')['route'] ?? null,
        );

        config()->set('modules.enabled', ['core']);

        $disabledItems = collect(app(ModuleManager::class)->navigationItems());

        $this->assertNull($disabledItems->firstWhere('module', 'forms'));

        $this->actingAs(User::factory()->create())
            ->get(route('crm.forms.index'))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function publishedForm(
        string $key,
        array $settings,
        bool $public = true,
    ): FormDefinition {
        $definition = FormDefinition::factory()->active()->create([
            'key' => $key,
            'is_public' => $public,
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => str($key)->replace('_', ' ')->title()->toString(),
            'schema' => $this->schema(),
            'settings' => $settings,
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionSettings(): array
    {
        return [
            'submission' => [
                'contact' => [
                    'fields' => ['email' => 'email'],
                    'source' => 'engage_sites',
                    'subsource' => 'artist_updates',
                ],
                'tags' => [[
                    'field' => 'interests',
                    'values' => ['music' => 'interest:music'],
                ]],
                'consents' => [[
                    'field' => 'email_marketing_consent',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                ]],
                'verification' => [
                    'required' => false,
                    'providers' => ['turnstile'],
                    'max_age_seconds' => 300,
                    'action' => 'artist_updates',
                    'require_hostname' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'sections' => [[
                'key' => 'contact',
                'label' => 'Contact',
                'fields' => [
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
                        'required' => false,
                        'options' => [[
                            'value' => 'music',
                            'label' => 'Music',
                        ]],
                    ],
                    [
                        'key' => 'email_marketing_consent',
                        'label' => 'Email consent',
                        'type' => 'checkbox',
                        'required' => false,
                    ],
                ],
            ]],
        ];
    }
}