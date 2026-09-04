<?php

namespace App\Modules\Broadcasts\Messaging;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;

final class BroadcastReusableMessageTemplateAuthoringContributor implements ReusableMessageTemplateAuthoringOptionContributor
{
    public function __construct(
        private readonly MessageChannelAvailability $channels,
    ) {}

    public function options(): iterable
    {
        $available = $this->channels->visibleChannelsForSurface(
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
            requireProvider: false,
        );

        if ($available === []) {
            $available = ['email'];
        }

        foreach ($available as $index => $channel) {
            if (! in_array($channel, ['email', 'sms'], true)) {
                continue;
            }

            $label = $channel === 'sms' ? 'SMS' : 'Email';

            yield new ReusableMessageTemplateAuthoringOption(
                key: 'broadcasts.marketing.'.$channel,
                label: 'Broadcast marketing — '.$label,
                description: 'Reusable marketing copy for one-time Broadcasts. It is also available to Annual Touches when the channel matches.',
                channel: $channel,
                context: new ReusableMessageTemplateAuthoringContext(
                    contextKey: 'broadcasts',
                    purpose: 'marketing',
                    scope: 'broadcast',
                    dispatchKey: Broadcast::DEFAULT_DISPATCH_KEY,
                    messageType: 'broadcast',
                    payloadClass: $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
                    queue: 'marketing',
                    moduleKey: 'broadcasts',
                    moduleLabel: 'Broadcasts',
                    surface: 'broadcasts',
                    groupKey: 'saved_broadcast_messages_'.$channel,
                    groupLabel: 'Saved Broadcast Messages — '.$label,
                    usageType: 'broadcast_reuse',
                    selectionContexts: ['broadcasts', 'campaign_annual_touch'],
                    description: 'Reusable CRM-authored Broadcast message.',
                ),
                namePlaceholder: $channel === 'sms'
                    ? 'September client update — SMS'
                    : 'September client update — Email',
                order: 100 + $index,
            );
        }
    }
}