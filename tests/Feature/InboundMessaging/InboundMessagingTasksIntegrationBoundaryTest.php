<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\ModuleIntegrations\InboundMessaging\Tasks\InboundMessageTaskLinkPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InboundMessagingTasksIntegrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'tasks',
        ]);

        (new IntegrationsModuleServiceProvider($this->app))->register();
    }

    public function test_inbound_messaging_module_source_does_not_import_tasks_module(): void
    {
        $violations = collect(File::allFiles(app_path('Modules/InboundMessaging')))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->filter(fn ($file): bool => str_contains(
                File::get($file->getPathname()),
                'App\\Modules\\Tasks\\',
            ))
            ->map(fn ($file): string => str_replace(
                base_path().DIRECTORY_SEPARATOR,
                '',
                $file->getPathname(),
            ))
            ->values()
            ->all();

        $this->assertSame([], $violations);
    }

    public function test_tasks_resolve_inbound_reply_context_through_the_app_integration_perimeter(): void
    {
        $presenters = collect($this->app->tagged('tasks.link_presenters'));

        $this->assertTrue(
            $presenters->contains(
                fn (mixed $presenter): bool =>
                    $presenter instanceof InboundMessageTaskLinkPresenter,
            ),
        );

        $contact = Contact::factory()->create();

        $outbound = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'message_type' => 'follow_up',
                'payload' => [
                    'subject' => 'Original follow-up',
                    'body' => 'Checking in.',
                ],
            ]);

        $inbound = InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'client_key' => config('client.key'),
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'event-task-integration',
            'provider_message_id' => 'message-task-integration',
            'from_type' => 'email',
            'from_value' => $contact->email,
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'body' => 'Please call me.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'correlated_scheduled_message_id' => $outbound->getKey(),
            'received_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ]);

        $task = Task::factory()->create();

        $task->links()->create([
            'linkable_type' => $inbound->getMorphClass(),
            'linkable_id' => $inbound->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);

        $presentation = app(TaskLinkPresentationResolver::class)
            ->forTask($task->fresh())
            ->first(fn (array $link): bool =>
                ($link['record'] ?? null) instanceof InboundMessage,
            );

        $this->assertIsArray($presentation);
        $this->assertTrue($presentation['record']->is($inbound));
        $this->assertSame('inbound_message', $presentation['kind']);
        $this->assertSame($inbound->body, $presentation['message']);
        $this->assertSame(
            (int) $outbound->getKey(),
            data_get($presentation, 'reply_to.scheduled_message_id'),
        );
        $this->assertSame(
            route('crm.inbound-messaging.inbox.show', $inbound),
            $presentation['url'],
        );
    }
}