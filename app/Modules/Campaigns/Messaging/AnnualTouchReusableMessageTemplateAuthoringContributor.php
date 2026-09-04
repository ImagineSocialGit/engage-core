<?php

namespace App\Modules\Campaigns\Messaging;

use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

final class AnnualTouchReusableMessageTemplateAuthoringContributor implements ReusableMessageTemplateAuthoringOptionContributor
{
    public function options(): iterable
    {
        foreach (['email', 'sms'] as $index => $channel) {
            $label = $channel === 'sms' ? 'SMS' : 'Email';

            yield new ReusableMessageTemplateAuthoringOption(
                key: 'campaigns.annual_touch.'.$channel,
                label: 'Annual touch — '.$label,
                description: 'Reusable marketing copy for birthdays, anniversaries, holidays, and other recurring annual touch dates.',
                channel: $channel,
                context: new ReusableMessageTemplateAuthoringContext(
                    contextKey: 'campaign_annual_touch',
                    purpose: CampaignTouchProgram::MESSAGE_PURPOSE,
                    scope: CampaignTouchProgram::MESSAGE_SCOPE,
                    dispatchKey: ProcessDueCampaignTouchDatesAction::DISPATCH_KEY,
                    messageType: 'campaign_annual_touch',
                    payloadClass: $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
                    queue: 'marketing',
                    moduleKey: 'campaigns',
                    moduleLabel: 'Campaigns',
                    surface: 'campaigns',
                    groupKey: 'annual_touches:'.$channel,
                    groupLabel: 'Annual Touches — '.$label,
                    usageType: 'campaign_annual_touch',
                    selectionContexts: ['campaign_annual_touch'],
                    description: 'Reusable CRM-authored standalone annual-touch message.',
                ),
                namePlaceholder: $channel === 'sms'
                    ? 'Birthday check-in — SMS'
                    : 'Birthday check-in — Email',
                order: 200 + $index,
            );
        }
    }
}