<?php

namespace Tests\Feature\Forms;

use App\Modules\Events\Models\Event;
use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\Forms\Services\HostedFormContextRegistry;
use App\Modules\Forms\Services\HostedFormContextService;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostedFormContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'forms',
            'events',
        ]);
        config()->set('human_verification.enabled', false);

        $this->app->register(FormsModuleServiceProvider::class, true);
        $this->app->register(IntegrationsModuleServiceProvider::class, true);
        $this->app->forgetInstance(HostedFormContextRegistry::class);
    }

    public function test_event_context_url_is_stable_and_persists_trusted_submission_attribution(): void
    {
        $this->publishedHostedForm();
        $event = Event::factory()->upcoming()->create([
            'title' => 'Nashville Fan Show',
            'starts_at' => '2026-10-24 01:00:00',
            'timezone' => 'America/Chicago',
            'venue_name' => 'The Listening Room',
            'city' => 'Nashville',
            'region' => 'TN',
            'postal_code' => '37201',
            'country' => 'US',
        ]);
        $context = new HostedFormContextReference(
            key: 'event',
            reference: (int) $event->getKey(),
        );
        $contexts = app(HostedFormContextService::class);

        $signedUrl = $contexts->signedUrl('artist_updates', $context);

        $this->assertSame(
            $signedUrl,
            $contexts->signedUrl('artist_updates', $context),
        );

        $response = $this->get($signedUrl)
            ->assertOk()
            ->assertViewIs('forms.show');

        $hiddenContext = collect($response->viewData('form')['hidden_fields'])
            ->firstWhere('key', HostedFormContextService::POST_FIELD);

        $this->assertIsArray($hiddenContext);
        $this->assertIsString($hiddenContext['default']);
        $this->assertNotSame('', trim($hiddenContext['default']));

        $this->post($this->formUrl(), [
            'email' => 'fan@example.com',
            HostedFormContextService::POST_FIELD => $hiddenContext['default'],
        ])
            ->assertRedirect($this->formUrl())
            ->assertSessionHas('forms.hosted.success', 'artist_updates');

        $submission = FormSubmission::query()->sole();

        $this->assertSame($event->getMorphClass(), $submission->subject_type);
        $this->assertSame((int) $event->getKey(), $submission->subject_id);
        $this->assertSame(
            'event',
            data_get($submission->meta, '_forms.context.key'),
        );
        $this->assertSame(
            'Nashville Fan Show',
            data_get($submission->meta, '_forms.context.attributes.event.title'),
        );
        $this->assertSame(
            'The Listening Room',
            data_get($submission->meta, '_forms.context.attributes.event.venue_name'),
        );
        $this->assertSame(
            'Nashville',
            data_get($submission->meta, '_forms.context.attributes.event.city'),
        );

        $automationEvent = AutomationEventOutboxEvent::query()
            ->where('event_key', 'form.submitted')
            ->sole();

        $this->assertSame(
            (int) $event->getKey(),
            data_get($automationEvent->payload, 'context.subject.id'),
        );
        $this->assertSame(
            '37201',
            data_get($automationEvent->payload, 'context.attributes.event.postal_code'),
        );
    }

    public function test_external_replay_uses_stable_context_identity_not_mutable_event_snapshot(): void
    {
        $this->publishedHostedForm();
        $event = Event::factory()->create([
            'title' => 'Original title',
        ]);
        $reference = new HostedFormContextReference(
            key: 'event',
            reference: (int) $event->getKey(),
        );
        $registry = app(HostedFormContextRegistry::class);
        $action = app(CreateFormSubmissionAction::class);

        $first = $action->handle(new FormSubmissionInput(
            formKey: 'artist_updates',
            values: ['email' => 'replay@example.com'],
            source: 'integration_test',
            provider: 'integration_test',
            externalId: 'event-context-replay-1',
            publicOnly: true,
            context: $registry->resolve($reference),
        ));

        $event->update(['title' => 'Updated title']);

        $replay = $action->handle(new FormSubmissionInput(
            formKey: 'artist_updates',
            values: ['email' => 'replay@example.com'],
            source: 'integration_test',
            provider: 'integration_test',
            externalId: 'event-context-replay-1',
            publicOnly: true,
            context: $registry->resolve($reference),
        ));

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->submissionId, $replay->submissionId);
        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(
            'Original title',
            data_get(
                FormSubmission::query()->sole()->meta,
                '_forms.context.attributes.event.title',
            ),
        );
    }

    public function test_tampered_event_context_link_fails_closed(): void
    {
        $this->publishedHostedForm();
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $contexts = app(HostedFormContextService::class);
        $signedUrl = $contexts->signedUrl(
            'artist_updates',
            new HostedFormContextReference(
                key: 'event',
                reference: (int) $event->getKey(),
            ),
        );
        $tamperedUrl = preg_replace(
            '/context_ref='.preg_quote((string) $event->getKey(), '/').'/',
            'context_ref='.$otherEvent->getKey(),
            $signedUrl,
            1,
        );

        $this->assertIsString($tamperedUrl);
        $this->get($tamperedUrl)->assertNotFound();
    }

    public function test_tampered_post_context_token_fails_closed_without_creating_submission(): void
    {
        $this->publishedHostedForm();
        $event = Event::factory()->create();
        $contexts = app(HostedFormContextService::class);
        $signedUrl = $contexts->signedUrl(
            'artist_updates',
            new HostedFormContextReference(
                key: 'event',
                reference: (int) $event->getKey(),
            ),
        );
        $response = $this->get($signedUrl)->assertOk();
        $token = collect($response->viewData('form')['hidden_fields'])
            ->firstWhere('key', HostedFormContextService::POST_FIELD)['default'];

        $this->post($this->formUrl(), [
            'email' => 'fan@example.com',
            HostedFormContextService::POST_FIELD => $token.'tampered',
        ])->assertNotFound();

        $this->assertSame(0, FormSubmission::query()->count());
    }

    private function formUrl(): string
    {
        return route('forms.public.store', [
            'formSlug' => 'artist-updates',
        ]);
    }

    private function publishedHostedForm(): FormDefinition
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
                    'label' => 'Stay in touch',
                    'fields' => [[
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ]],
                ]],
            ],
            'rules' => [],
            'layout' => [],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email'],
                        'source' => 'core_hosted_forms',
                        'subsource' => 'artist_updates',
                    ],
                ],
                'public' => [
                    'hosted' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }
}