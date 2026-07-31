<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CanonicalMessageTemplateRuntimeCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_runtime_definition_uses_and_pins_the_canonical_template_version(): void
    {
        Queue::fake();

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.fixture.primary',
            'name' => 'Fixture Template',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'fixture_notice',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['fixture_ready'],
            'payload' => [
                'subject' => 'Stale projection subject',
                'body' => 'Stale projection body.',
            ],
            'tokens' => [],
            'source' => 'config',
            'source_version' => 1,
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'definition_key' => 'primary',
                'message_type' => 'fixture_notice',
            ]);

        $template = MessageTemplate::query()->create([
            'key' => $preset->key,
            'name' => 'Fixture Template',
            'description' => null,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'config',
            'source_version' => '1',
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Canonical fixture subject',
                'body' => 'Canonical fixture body.',
            ],
        );

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'fixture',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame($template->getKey(), $definitions[0]['message_template_id']);
        $this->assertSame($version->getKey(), $definitions[0]['message_template_version_id']);
        $this->assertSame('Canonical fixture subject', $definitions[0]['payload']['subject']);
        $this->assertSame('Canonical fixture body.', $definitions[0]['payload']['body']);
        $this->assertNotSame('Stale projection subject', $definitions[0]['payload']['subject']);

        $contact = Contact::factory()->create([
            'email' => 'fixture@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'fixture',
            dispatchKeys: 'fixture_ready',
            behavior: [
                'timing' => 'immediate',
            ],
        );

        $this->assertCount(1, $messages);

        $scheduledMessage = ScheduledMessage::query()
            ->with('messageTemplateVersion')
            ->firstOrFail();

        $this->assertSame($version->getKey(), $scheduledMessage->message_template_version_id);
        $this->assertTrue($scheduledMessage->messageTemplateVersion->is($version));
        $this->assertSame('Canonical fixture subject', $scheduledMessage->payload['subject']);
        $this->assertSame('Canonical fixture body.', $scheduledMessage->payload['body']);

        Queue::assertPushed(SendScheduledMessageJob::class);
    }
}