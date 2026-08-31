<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionAutomationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_submission_records_one_durable_route_event(): void
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'consultation_request',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Consultation request',
            'schema' => ['sections' => [[
                'key' => 'contact',
                'fields' => [
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ],
            ]]],
            'rules' => [],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email'],
                        'source' => 'engage_sites',
                        'subsource' => 'consultation_request',
                    ],
                ],
            ],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        $result = app(CreateFormSubmissionAction::class)->handle(
            new FormSubmissionInput(
                formKey: 'consultation_request',
                values: ['email' => 'person@example.com'],
                source: 'engage_sites',
                publicOnly: true,
            ),
        );

        $event = AutomationEventOutboxEvent::query()
            ->where('event_key', 'form.submitted')
            ->sole();

        $this->assertSame($result->contactId, $event->contact_id);
        $this->assertSame('consultation_request', data_get($event->payload, 'form.key'));
        $this->assertSame($result->submissionId, data_get($event->payload, 'form_submission.id'));
    }
}