<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\EditScheduledMessageContentAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageEdit;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduledMessageContentEditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_edit_and_restore_are_append_only_and_change_resolved_send_content(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['phone' => '+13125550123']);
        $message = ScheduledMessage::factory()->forRecipient($contact)->create([
            'channel' => 'sms',
            'payload_class' => \App\Modules\Messaging\Payloads\SmsPayload::class,
            'payload' => [
                'to' => '+13125550123',
                'channel' => 'sms',
                'purpose' => 'marketing',
                'scope' => 'general',
                'message_type' => 'reminder',
                'message' => 'Original text',
            ],
            'send_at' => now()->addHour(),
        ]);
        $editor = app(EditScheduledMessageContentAction::class);
        $editor->save($message, $user, ['message' => 'Edited text']);
        $this->assertStringContainsString('Edited text', app(ScheduledMessagePayloadResolver::class)
            ->resolve($message->fresh())->message());

        $editor->restore($message, $user);
        $this->assertStringContainsString('Original text', app(ScheduledMessagePayloadResolver::class)
            ->resolve($message->fresh())->message());
        $this->assertSame(2, ScheduledMessageEdit::query()
            ->where('scheduled_message_id', $message->getKey())->count());
    }

    public function test_claimed_message_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $message = ScheduledMessage::factory()->create(['status' => ScheduledMessage::STATUS_SENDING]);

        $this->expectException(ValidationException::class);
        app(EditScheduledMessageContentAction::class)
            ->save($message, $user, ['message' => 'Too late']);
    }

    public function test_versioned_edit_wins_after_render_values_are_frozen(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Original Name',
            'email' => 'fixture@example.test',
        ]);
        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.fixture.individual_edit',
            'name' => 'Individual Edit Fixture',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'source_version' => '1',
            'is_customized' => false,
        ]);
        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            ['subject' => 'Reminder', 'body' => 'Hello {first_name}.'],
        );
        $message = ScheduledMessage::factory()->forRecipient($contact)->create([
            'message_template_version_id' => $version->getKey(),
            'payload' => ['to' => 'fixture@example.test'],
        ]);

        $resolver = app(ScheduledMessagePayloadResolver::class);
        $this->assertSame('Hello Original Name.', $resolver->resolve($message)->text());

        app(EditScheduledMessageContentAction::class)->save($message, $user, [
            'subject' => 'Updated reminder',
            'body' => 'Updated for {first_name}.',
        ]);

        $contact->forceFill(['first_name' => 'Changed Name'])->save();

        $payload = $resolver->resolve($message->fresh());
        $this->assertSame('Updated reminder', $payload->subject());
        $this->assertSame('Updated for Original Name.', $payload->text());
    }
}