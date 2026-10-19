<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HostedFormLocalVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
    }

    public function test_local_hosted_submission_may_omit_required_verification_when_public_verification_is_disabled(): void
    {
        $this->publishedRequiredVerificationForm();

        $url = 'http://forms.'.config('app.root_domain').'/local-verification-test';

        $this->post($url, [
            'email' => 'fan@example.com',
        ])
            ->assertRedirect($url)
            ->assertSessionHas('forms.hosted.success', 'local_verification_test');

        $submission = FormSubmission::query()->sole();

        $this->assertSame('fan@example.com', $submission->payload['email']);
        $this->assertArrayNotHasKey(
            'verification',
            $submission->meta['_forms'],
        );
    }

    private function publishedRequiredVerificationForm(): void
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'local_verification_test',
            'name' => 'Local Verification Test',
        ]);

        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Local Verification Test',
            'schema' => [
                'sections' => [[
                    'key' => 'signup',
                    'fields' => [[
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ]],
                ]],
            ],
            'rules' => [
                'email' => ['required', 'email', 'max:255'],
            ],
            'layout' => [],
            'settings' => [
                'public' => [
                    'hosted' => [
                        'enabled' => true,
                    ],
                ],
                'submission' => [
                    'verification' => [
                        'required' => true,
                        'providers' => ['turnstile'],
                        'max_age_seconds' => 300,
                        'action' => 'forms',
                        'require_hostname' => true,
                    ],
                ],
            ],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();
    }
}