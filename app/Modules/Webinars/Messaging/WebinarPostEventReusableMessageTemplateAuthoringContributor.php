<?php

namespace App\Modules\Webinars\Messaging;

use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

final class WebinarPostEventReusableMessageTemplateAuthoringContributor implements ReusableMessageTemplateAuthoringOptionContributor
{
    public function options(): iterable
    {
        foreach (['sms', 'email'] as $index => $channel) {
            $label = $channel === 'sms' ? 'Text' : 'Email';

            yield new ReusableMessageTemplateAuthoringOption(
                key: 'webinars.post_event.'.$channel,
                label: 'Post-webinar follow-up — '.$label,
                description: 'Copy for a webinar follow-up message with its own trigger, recipient conditions and timing.',
                channel: $channel,
                context: new ReusableMessageTemplateAuthoringContext(
                    contextKey: 'webinar_post_event_plan',
                    purpose: 'transactional',
                    scope: 'webinar',
                    dispatchKey: 'webinar_post_event_plan',
                    messageType: 'post_event_plan',
                    payloadClass: $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
                    queue: 'post_event',
                    moduleKey: 'webinars',
                    moduleLabel: 'Webinars',
                    surface: 'webinar_registrations',
                    groupKey: 'webinars:post_event:'.$channel,
                    groupLabel: 'Post-webinar follow-up messages',
                    usageType: 'webinar_post_event',
                    selectionContexts: ['webinar_post_event_plan'],
                    description: 'Reusable post-webinar follow-up message.',
                ),
                namePlaceholder: 'VA follow-up — '.$label,
                order: 400 + $index,
            );
        }
    }
}