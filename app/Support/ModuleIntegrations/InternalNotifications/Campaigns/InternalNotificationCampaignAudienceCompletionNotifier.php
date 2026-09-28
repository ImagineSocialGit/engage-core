<?php

namespace App\Support\ModuleIntegrations\InternalNotifications\Campaigns;

use App\Modules\Campaigns\Contracts\CampaignAudienceCompletionNotifier;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Enums\MessageChannel;

final class InternalNotificationCampaignAudienceCompletionNotifier implements CampaignAudienceCompletionNotifier
{
    private const TYPE = 'campaign_audience_completed';

    public function __construct(private readonly ScheduleInternalNotificationAction $schedule) {}

    public function notify(Campaign $campaign, int $completedCount, int $cycle): bool
    {
        $scheduled = false;

        foreach (TeamMember::query()->active()->whereNotNull('email')->orderBy('id')->cursor() as $member) {
            if (! $member->canReceiveEmailNotifications(self::TYPE)) {
                continue;
            }

            $name = trim((string) $member->name);
            $subject = $campaign->name.' audience has finished';

            $message = $this->schedule->handle(
                recipient: new InternalNotificationRecipient(
                    source: $member,
                    name: $name !== '' ? $name : (string) $member->email,
                    email: $member->email,
                    phone: $member->phone,
                    notificationType: self::TYPE,
                    preferenceOwner: $member,
                ),
                scope: 'campaign_audience',
                messageType: self::TYPE,
                content: [
                    'subject' => $subject,
                    'headline' => $subject,
                    'preheader' => 'The current Campaign audience has finished its messages.',
                    'body' => [
                        number_format($completedCount).' leads have completed this Campaign.',
                        'Review the audience before planning or appending the next message.',
                    ],
                    'cta' => [
                        'label' => 'Review Campaign audience',
                        'url' => route('crm.campaigns.audience.index', $campaign),
                    ],
                ],
                context: $campaign,
                dedupeKey: 'campaign_audience_completed:'.$campaign->getKey().':'.$cycle.':'.$member->getKey(),
                meta: ['campaign_id' => $campaign->getKey(), 'cycle' => $cycle],
                allowedChannels: [MessageChannel::Email],
            );

            $scheduled = $message !== null || $scheduled;
        }

        return $scheduled;
    }
}