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
        foreach (['id', 'key', 'name', 'description', 'channel', 'purpose', 'scope', 'status', 'is_active', 'source_version', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign.{$column}", 'campaigns', "Campaign {$column}", "Value stored in campaigns.{$column}.", Campaign::class, $column);
        }

        foreach (['id', 'contact_id', 'campaign_id', 'campaign_key', 'status', 'current_step', 'current_campaign_step_id', 'last_scheduled_message_id', 'started_at', 'completed_at', 'exited_at', 'exit_reason', 'created_at', 'updated_at'] as $column) {
            yield TokenSourceDefinition::modelColumn("campaign_enrollment.{$column}", 'campaigns', "Campaign enrollment {$column}", "Value stored in campaign_enrollments.{$column}.", CampaignEnrollment::class, $column);
        }
    }
}
