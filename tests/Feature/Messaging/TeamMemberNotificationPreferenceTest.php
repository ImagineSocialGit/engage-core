<?php

namespace Tests\Feature\Messaging;

use App\Models\TeamMember;
use App\Models\TeamMemberNotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_team_member_with_email_can_receive_email_notifications_by_default(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
            'active' => true,
        ]);

        $this->assertTrue(
            $teamMember->canReceiveEmailNotifications(
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES
            )
        );
    }

    public function test_inactive_team_member_cannot_receive_email_notifications(): void
    {
        $teamMember = TeamMember::factory()->inactive()->create([
            'email' => 'admin@example.com',
        ]);

        $this->assertFalse(
            $teamMember->canReceiveEmailNotifications(
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES
            )
        );
    }

    public function test_disabled_email_preference_blocks_email_notifications(): void
    {
        $teamMember = TeamMember::factory()->create([
            'email' => 'admin@example.com',
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->email()
            ->inboundReplies()
            ->disabled()
            ->create();

        $teamMember->load('notificationPreferences');

        $this->assertFalse(
            $teamMember->canReceiveEmailNotifications(
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES
            )
        );
    }

    public function test_sms_notifications_default_off(): void
    {
        $teamMember = TeamMember::factory()->create([
            'phone' => '+15551234567',
        ]);

        $this->assertFalse(
            $teamMember->canReceiveSmsNotifications(
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES
            )
        );
    }

    public function test_enabled_sms_preference_allows_sms_notifications(): void
    {
        $teamMember = TeamMember::factory()->create([
            'phone' => '+15551234567',
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->sms()
            ->inboundReplies()
            ->create([
                'enabled' => true,
            ]);

        $teamMember->load('notificationPreferences');

        $this->assertTrue(
            $teamMember->canReceiveSmsNotifications(
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES
            )
        );
    }
}