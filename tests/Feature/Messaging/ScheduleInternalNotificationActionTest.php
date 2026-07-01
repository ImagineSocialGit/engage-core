<?php

namespace Tests\Feature\Messaging;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleInternalNotificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_schedule_sms_when_sms_is_not_available_for_internal_notifications(): void
    {
        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.internal_notifications', false);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'internal:inbound_messages' => true,
        ]);

        $teamMember = TeamMember::factory()->create([
            'email' => null,
            'phone' => '+15551234567',
            'is_active' => true,
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->sms()
            ->inboundReplies()
            ->create([
                'is_enabled' => true,
            ]);

        $teamMember->refresh()->load('notificationPreferences');

        $this->mock(ScheduleMessageAction::class)
            ->shouldNotReceive('handle');

        $scheduledMessage = app(ScheduleInternalNotificationAction::class)->handle(
            recipient: $this->recipient($teamMember),
            scope: 'inbound_messages',
            messageType: 'inbound_reply',
            content: [
                'subject' => 'New inbound reply',
                'body' => 'A lead replied.',
                'message' => 'A lead replied.',
            ],
            allowedChannels: [MessageChannel::Sms],
        );

        $this->assertNull($scheduledMessage);
        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    public function test_it_schedules_sms_when_sms_is_available_and_internal_preferences_allow_it(): void
    {
        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.internal_notifications', true);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'internal:inbound_messages' => true,
        ]);

        $teamMember = TeamMember::factory()->create([
            'email' => null,
            'phone' => '+15551234567',
            'is_active' => true,
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->sms()
            ->inboundReplies()
            ->create([
                'is_enabled' => true,
            ]);

        $teamMember->refresh()->load('notificationPreferences');

        $this->mock(ScheduleMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($teamMember): ScheduledMessage {
                $this->assertSame(MessageChannel::Sms, $arguments['channel'] ?? $arguments[1]);
                $this->assertSame('internal', $arguments['purpose'] ?? $arguments[2]);
                $this->assertSame('inbound_messages', $arguments['scope'] ?? $arguments[3]);

                return ScheduledMessage::factory()->create([
                    'recipient_type' => $teamMember->getMorphClass(),
                    'recipient_id' => $teamMember->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'internal',
                    'scope' => 'inbound_messages',
                    'message_type' => 'inbound_reply',
                ]);
            });

        $scheduledMessage = app(ScheduleInternalNotificationAction::class)->handle(
            recipient: $this->recipient($teamMember),
            scope: 'inbound_messages',
            messageType: 'inbound_reply',
            content: [
                'subject' => 'New inbound reply',
                'body' => 'A lead replied.',
                'message' => 'A lead replied.',
            ],
            allowedChannels: [MessageChannel::Sms],
        );

        $this->assertNotNull($scheduledMessage);
        $this->assertSame('sms', $scheduledMessage->channel);
    }

    private function recipient(TeamMember $teamMember): InternalNotificationRecipient
    {
        return new InternalNotificationRecipient(
            source: $teamMember,
            name: $teamMember->name,
            email: $teamMember->email,
            phone: $teamMember->phone,
            notificationType: TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            preferenceOwner: $teamMember,
        );
    }
}