<?php

namespace Tests\Feature\InternalNotifications;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Actions\SaveInternalNotificationRecipientAction;
use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Support\ModuleIntegrations\InternalNotifications\InboundMessaging\InboundMessageNotificationRecipientResolver;
use App\Support\ModuleIntegrations\InternalNotifications\InboundMessaging\ScheduleInboundMessageInternalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InboundReplyNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_recipient_persists_explicit_reply_and_scheduled_report_email_preferences(): void
    {
        $recipient = app(SaveInternalNotificationRecipientAction::class)->handle(
            name: 'Operations Team',
            email: 'OPS@EXAMPLE.TEST',
            isActive: true,
            receiveInboundReplies: true,
            receiveScheduledReports: false,
        );

        $this->assertSame('ops@example.test', $recipient->email);

        $this->assertDatabaseHas('team_member_notification_preferences', [
            'team_member_id' => $recipient->getKey(),
            'channel' => 'email',
            'purpose' => TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            'scope' => null,
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('team_member_notification_preferences', [
            'team_member_id' => $recipient->getKey(),
            'channel' => 'email',
            'purpose' => TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
            'scope' => null,
            'is_enabled' => false,
        ]);
    }

    public function test_normal_reply_uses_only_explicit_settings_subscribers_even_when_legacy_fallback_is_configured(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Alex Reply',
            'email' => 'alex@example.test',
        ]);

        $message = InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'from_type' => 'phone',
            'from_value' => '+16155550123',
            'to_type' => 'phone',
            'to_value' => '+16155550999',
            'body' => 'Yes, please call me today.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'received_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ]);

        $enabledA = $this->recipient('First Recipient', 'first@example.test', true);
        $enabledB = $this->recipient('Second Recipient', 'second@example.test', true);
        $this->recipient('Disabled Recipient', 'disabled@example.test', false);

        $legacyFallback = TeamMember::factory()->create([
            'name' => 'Legacy Fallback',
            'email' => 'legacy@example.test',
            'is_active' => true,
        ]);

        config()->set(
            'messaging.internal_notifications.inbound_replies.default_team_member_email',
            $legacyFallback->email,
        );

        $resolvedIds = app(InboundMessageNotificationRecipientResolver::class)
            ->resolveAll($message)
            ->map(fn ($recipient): int => (int) $recipient->source->getKey())
            ->all();

        $this->assertEqualsCanonicalizing(
            [$enabledA->getKey(), $enabledB->getKey()],
            $resolvedIds,
        );
        $this->assertNotContains($legacyFallback->getKey(), $resolvedIds);

        $scheduled = [];

        $scheduler = Mockery::mock(ScheduleInternalNotificationAction::class);
        $scheduler->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (...$arguments) use (&$scheduled) {
                $scheduled[] = $arguments;

                return null;
            });

        $listener = new ScheduleInboundMessageInternalNotification(
            app(InboundMessageNotificationRecipientResolver::class),
            $scheduler,
        );

        $listener->handle(new InboundMessageReceived($message));

        $this->assertCount(2, $scheduled);

        foreach ($scheduled as $arguments) {
            $content = $arguments['content'] ?? $arguments[3] ?? [];

            $this->assertSame(
                'Yes, please call me today.',
                $content['details']['Message'] ?? null,
            );
            $this->assertSame(
                route('crm.contacts.show', $contact),
                $content['cta']['url'] ?? null,
            );
        }

        $this->assertNotNull($message->fresh()?->processed_at);
    }

    private function recipient(
        string $name,
        string $email,
        bool $enabled,
    ): TeamMember {
        return app(SaveInternalNotificationRecipientAction::class)->handle(
            name: $name,
            email: $email,
            isActive: true,
            receiveInboundReplies: $enabled,
            receiveScheduledReports: false,
        );
    }
}