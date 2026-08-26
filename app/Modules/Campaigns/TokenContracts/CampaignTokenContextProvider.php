<?php

namespace App\Modules\Campaigns\TokenContracts;

use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;

class CampaignTokenContextProvider implements TokenContextProvider
{
    public function contexts(): iterable
    {
        yield new TokenContextDefinition(
            key: 'campaign_step_due',
            owner: 'campaigns',
            description: 'Campaign step message rendering.',
            sourceTokens: [
                'contact.first_name',
                'contact.last_name',
                'contact.name',
                'contact.email',
                'contact.phone',
                'contact.birthday',
                'campaign.id',
                'campaign.key',
                'campaign.name',
                'campaign.description',
                'campaign.channel',
                'campaign.purpose',
                'campaign.scope',
                'campaign.status',
                'campaign_enrollment.id',
                'campaign_enrollment.campaign_key',
                'campaign_enrollment.message_chain_enrollment_id',
                'campaign_enrollment.started_at',
            ],
            channels: ['email', 'sms'],
            purposes: ['marketing'],
            surfaces: ['campaigns'],
        );

        yield new TokenContextDefinition(
            key: ProcessDueCampaignTouchDatesAction::DISPATCH_KEY,
            owner: 'campaigns',
            description: 'Standalone recurring annual touch message rendering.',
            sourceTokens: [
                'contact.first_name',
                'contact.last_name',
                'contact.name',
                'contact.email',
                'contact.phone',
                'contact.birthday',
            ],
            channels: ['email', 'sms'],
            purposes: ['marketing'],
            surfaces: ['campaigns'],
        );
    }
}