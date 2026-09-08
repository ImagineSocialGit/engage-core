<?php

namespace App\Modules\Broadcasts\Access;

use App\Modules\Core\Access\Contracts\AccessCapabilityContributor;
use App\Modules\Core\Access\Data\AccessCapabilityDefinition;

final class BroadcastsAccessCapabilityContributor implements AccessCapabilityContributor
{
    public const CREATE_FROM_CONTACT_RESULTS = 'broadcasts.create_from_contact_results';

    public function capabilities(): array
    {
        return [
            new AccessCapabilityDefinition(
                key: self::CREATE_FROM_CONTACT_RESULTS,
                label: 'Create Broadcasts from Contact results',
                description: 'Carry a visible Contact result set into the Broadcast audience builder.',
            ),
        ];
    }
}