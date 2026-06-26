<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\InternalNotifications\Services\InternalNotificationChannelResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalNotificationChannelResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_email_by_default_when_recipient_can_receive_email(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
            'phone' => '+15551234567',
            'is_active' => true,
        ]);

        $recipient = $this->recipient($teamMember);

        $this->assertSame(
            MessageChannel::Email,
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
            )
        );
    }

    public function test_resolves_sms_when_email_is_not_allowed_and_sms_is_enabled(): void
    {
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

        $recipient = $this->recipient($teamMember);

        $this->assertSame(
            MessageChannel::Sms,
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
            )
        );
    }

    public function test_respects_allowed_channel_order(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
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

        $recipient = $this->recipient($teamMember);

        $this->assertSame(
            MessageChannel::Sms,
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
                allowedChannels: ['sms', 'email'],
            )
        );
    }

    public function test_respects_allowed_channel_constraint(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
            'phone' => '+15551234567',
            'is_active' => true,
        ]);

        $recipient = $this->recipient($teamMember);

        $this->assertNull(
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
                allowedChannels: ['sms'],
            )
        );
    }

    public function test_returns_null_when_no_allowed_channel_is_eligible(): void
    {
        $teamMember = TeamMember::factory()->inactive()->create([
            'email' => 'admin@example.com',
            'phone' => '+15551234567',
        ]);

        $recipient = $this->recipient($teamMember);

        $this->assertNull(
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
            )
        );
    }

    public function test_ignores_unknown_string_channels(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);

        $recipient = $this->recipient($teamMember);

        $this->assertSame(
            MessageChannel::Email,
            $this->resolver()->resolve(
                recipient: $recipient,
                notificationType: $recipient->notificationType,
                allowedChannels: ['fax', 'email'],
            )
        );
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

    private function resolver(): InternalNotificationChannelResolver
    {
        return app(InternalNotificationChannelResolver::class);
    }
}