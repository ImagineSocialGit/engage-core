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
        foreach (['id', 'key', 'name', 'description', 'channel', 'purpose', 'scope', 'status', 'source_version', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign.{$column}", 'campaigns', "Campaign {$column}", "Value stored in campaigns.{$column}.", Campaign::class, $column);
        }

        foreach (['id', 'contact_id', 'campaign_id', 'message_chain_enrollment_id', 'source_type', 'source_id', 'campaign_key', 'started_at', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign_enrollment.{$column}", 'campaigns', "Campaign enrollment {$column}", "Value stored in campaign_enrollments.{$column}.", CampaignEnrollment::class, $column);
        }
    }
}