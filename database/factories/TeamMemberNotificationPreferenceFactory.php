<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\TeamMemberNotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMemberNotificationPreference>
 */
class TeamMemberNotificationPreferenceFactory extends Factory
{
    protected $model = TeamMemberNotificationPreference::class;

    public function definition(): array
    {
        return [
            'team_member_id' => TeamMember::factory(),
            'channel' => TeamMemberNotificationPreference::CHANNEL_EMAIL,
            'notification_type' => TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            'enabled' => true,
        ];
    }

    public function email(): self
    {
        return $this->state([
            'channel' => TeamMemberNotificationPreference::CHANNEL_EMAIL,
        ]);
    }

    public function sms(): self
    {
        return $this->state([
            'channel' => TeamMemberNotificationPreference::CHANNEL_SMS,
        ]);
    }

    public function inboundReplies(): self
    {
        return $this->state([
            'notification_type' => TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
        ]);
    }

    public function disabled(): self
    {
        return $this->state([
            'enabled' => false,
        ]);
    }
}