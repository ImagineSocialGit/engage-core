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