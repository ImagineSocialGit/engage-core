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
            'purpose' => TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            'scope' => null,
            'is_enabled' => true,
            'meta' => null,
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
            'purpose' => TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
        ]);
    }

    public function taskAssigned(): self
    {
        return $this->state([
            'purpose' => TeamMemberNotificationPreference::TYPE_TASK_ASSIGNED,
        ]);
    }

    public function taskDue(): self
    {
        return $this->state([
            'purpose' => TeamMemberNotificationPreference::TYPE_TASK_DUE,
        ]);
    }

    public function dailyDigest(): self
    {
        return $this->state([
            'purpose' => TeamMemberNotificationPreference::TYPE_DAILY_DIGEST,
        ]);
    }

    public function weeklyDigest(): self
    {
        return $this->state([
            'purpose' => TeamMemberNotificationPreference::TYPE_WEEKLY_DIGEST,
        ]);
    }

    public function disabled(): self
    {
        return $this->state([
            'is_enabled' => false,
        ]);
    }
}