<?php

namespace Tests\Feature\Forms;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionValue;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms']);
    }

    public function test_form_submission_index_is_scoped_to_the_selected_form(): void
    {
        [$form, $version] = $this->publishedForm('artist_updates');
        [$otherForm, $otherVersion] = $this->publishedForm('vip_request');

        $visible = FormSubmission::factory()->forVersion($version)->create();
        $hidden = FormSubmission::factory()->forVersion($otherVersion)->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.forms.submissions.index', [
                'formDefinition' => $form->key,
            ]))
            ->assertOk()
            ->assertViewIs('crm.forms.submissions.index');

        $submissions = $response->viewData('submissions');

        $this->assertSame(1, $submissions->total());
        $this->assertSame($visible->getKey(), $submissions->items()[0]['id']);

        $response
            ->assertSee('data-form-submissions', false)
            ->assertSee('data-form-submission-id="'.$visible->getKey().'"', false)
            ->assertDontSee('data-form-submission-id="'.$hidden->getKey().'"', false);
    }

    public function test_submission_detail_exposes_normalized_review_contract_without_raw_transport_evidence(): void
    {
        [$form, $version] = $this->publishedForm('artist_updates');
        $contact = Contact::factory()->create([
            'name' => 'Example Fan',
            'email' => 'fan@example.com',
        ]);
        $submission = FormSubmission::factory()
            ->forVersion($version)
            ->create([
                'contact_id' => $contact->getKey(),
                'source' => 'engage_sites',
                'provider' => 'engage_sites',
                'external_id' => 'opaque-external-id-must-not-render',
                'raw_payload' => ['secret_transport_field' => 'must-not-render'],
                'meta' => [
                    '_forms' => [
                        'runtime_version' => 1,
                        'idempotency_fingerprint' => 'fingerprint-must-not-render',
                        'verification' => [
                            'version' => 1,
                            'provider' => 'turnstile',
                            'outcome' => 'passed',
                            'verified_at' => now()->toAtomString(),
                            'hostname' => 'staging.example.com',
                            'action' => 'artist_updates',
                            'authenticated_client_id' => 'engage_sites',
                        ],
                    ],
                ],
            ]);

        FormSubmissionValue::factory()->create([
            'form_submission_id' => $submission->getKey(),
            'field_key' => 'email',
            'field_label' => 'Email',
            'field_type' => 'email',
            'value' => ['value' => 'fan@example.com'],
            'value_text' => 'fan@example.com',
            'sort_order' => 10,
        ]);
        FormSubmissionValue::factory()
            ->boolean('email_marketing_consent', true)
            ->create([
                'form_submission_id' => $submission->getKey(),
                'field_label' => 'Email marketing consent',
                'sort_order' => 20,
            ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.forms.submissions.show', [
                'formSubmission' => $submission,
            ]))
            ->assertOk()
            ->assertViewIs('crm.forms.submissions.show');

        $detail = $response->viewData('submission');

        $this->assertSame($form->key, $detail['form']['key']);
        $this->assertSame($contact->getKey(), $detail['contact']['id']);
        $this->assertSame('fan@example.com', $detail['values'][0]['display_value']);
        $this->assertTrue($detail['consents'][0]['accepted']);
        $this->assertSame('turnstile', $detail['verification']['provider']);
        $this->assertSame('passed', $detail['verification']['outcome']);
        $this->assertArrayNotHasKey('external_id', $detail);
        $this->assertArrayNotHasKey('raw_payload', $detail);
        $this->assertArrayNotHasKey('meta', $detail);

        $response
            ->assertSee('data-form-submission-detail', false)
            ->assertSee('data-submission-value-key="email"', false)
            ->assertSee('data-consent-field="email_marketing_consent"', false)
            ->assertSee('data-verification-provider="turnstile"', false)
            ->assertDontSee('opaque-external-id-must-not-render')
            ->assertDontSee('secret_transport_field')
            ->assertDontSee('fingerprint-must-not-render');
    }

    public function test_submission_routes_are_hidden_when_forms_is_disabled(): void
    {
        [$form, $version] = $this->publishedForm('artist_updates');
        $submission = FormSubmission::factory()->forVersion($version)->create();

        config()->set('modules.enabled', ['core']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.forms.submissions.index', [
                'formDefinition' => $form->key,
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('crm.forms.submissions.show', [
                'formSubmission' => $submission,
            ]))
            ->assertNotFound();
    }

    /**
     * @return array{0: FormDefinition, 1: FormVersion}
     */
    private function publishedForm(string $key): array
    {
        $form = FormDefinition::factory()->active()->public()->create([
            'key' => $key,
            'name' => str($key)->replace('_', ' ')->title()->toString(),
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $form->getKey(),
            'version' => 1,
            'name' => $form->name,
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [[
                        'key' => 'email_marketing_consent',
                        'label' => 'Email marketing consent',
                        'type' => 'checkbox',
                        'required' => false,
                    ]],
                ]],
            ],
            'settings' => [
                'submission' => [
                    'consents' => [[
                        'field' => 'email_marketing_consent',
                        'channel' => 'email',
                        'purpose' => 'marketing',
                    ]],
                ],
            ],
        ]);

        $form->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return [$form->refresh(), $version->refresh()];
    }
}