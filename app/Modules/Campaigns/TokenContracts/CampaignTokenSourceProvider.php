<?php

namespace App\Modules\Campaigns\TokenContracts;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenSourceDefinition;

class CampaignTokenSourceProvider implements TokenSourceProvider
{
    public function sources(): iterable
    {
        yield TokenSourceDefinition::computed(
            token: 'annual_touch.occurrence_number',
            owner: 'campaigns',
            label: 'Anniversary number',
            description: 'The numeric anniversary or annual occurrence, such as 5.',
            sourcePath: 'annual_touch.occurrence_number',
            providerClass: CampaignAnnualTouchComputedTokenValueProvider::class,
            aliases: ['anniversary_number'],
            nullable: false,
        );

        yield TokenSourceDefinition::computed(
            token: 'annual_touch.occurrence_ordinal',
            owner: 'campaigns',
            label: 'Anniversary number (1st, 2nd…)',
            description: 'The same occurrence formatted for customer copy, such as 5th.',
            sourcePath: 'annual_touch.occurrence_ordinal',
            providerClass: CampaignAnnualTouchComputedTokenValueProvider::class,
            aliases: ['anniversary_ordinal'],
            nullable: false,
        );

        yield TokenSourceDefinition::computed(
            token: 'annual_touch.source_date',
            owner: 'campaigns',
            label: 'Original annual date',
            description: 'The original registered source date or configured annual date.',
            sourcePath: 'annual_touch.source_date',
            providerClass: CampaignAnnualTouchComputedTokenValueProvider::class,
            nullable: false,
        );

        foreach (['id', 'key', 'name', 'description', 'channel', 'purpose', 'scope', 'status', 'source_version', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign.{$column}", 'campaigns', "Campaign {$column}", "Value stored in campaigns.{$column}.", Campaign::class, $column);
        }

        foreach (['id', 'contact_id', 'campaign_id', 'message_chain_enrollment_id', 'source_type', 'source_id', 'campaign_key', 'started_at', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign_enrollment.{$column}", 'campaigns', "Campaign enrollment {$column}", "Value stored in campaign_enrollments.{$column}.", CampaignEnrollment::class, $column);
        }
    }
}