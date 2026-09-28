<?php

namespace App\Modules\Campaigns\Access;

use App\Modules\Core\Access\Contracts\AccessCapabilityContributor;
use App\Modules\Core\Access\Data\AccessCapabilityDefinition;

final class CampaignsAccessCapabilityContributor implements AccessCapabilityContributor
{
    public const ENROLL_CONTACT_RESULTS = 'campaigns.enroll_contact_results';

    public function capabilities(): array
    {
        return [
            new AccessCapabilityDefinition(
                key: self::ENROLL_CONTACT_RESULTS,
                label: 'Manage Campaign participation for Contact results',
                description: 'Enroll, restart, or manage message exclusions for a visible Contact result set.',
            ),
        ];
    }
}