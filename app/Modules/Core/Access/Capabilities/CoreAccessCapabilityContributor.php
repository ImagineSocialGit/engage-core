<?php

namespace App\Modules\Core\Access\Capabilities;

use App\Modules\Core\Access\Contracts\AccessCapabilityContributor;
use App\Modules\Core\Access\Data\AccessCapabilityDefinition;

final class CoreAccessCapabilityContributor implements AccessCapabilityContributor
{
    public function capabilities(): array
    {
        return [
            new AccessCapabilityDefinition(key: 'contacts.view_all', label: 'View all Contacts', description: 'See every Contact regardless of assignment.'),
            new AccessCapabilityDefinition(key: 'contacts.view_team', label: 'View Team Contacts', description: 'See Contacts assigned to any Team the user belongs to.'),
            new AccessCapabilityDefinition(key: 'contacts.view_unassigned', label: 'View unassigned Contacts', description: 'See Contacts that are not assigned to a user or Team.'),
            new AccessCapabilityDefinition(key: 'contacts.manage', label: 'Manage Contacts', description: 'Create and change Contacts that are visible to the user.'),
            new AccessCapabilityDefinition(key: 'contacts.import', label: 'Import Contacts', description: 'Use Contact import and import-batch maintenance surfaces.'),
            new AccessCapabilityDefinition(key: 'contacts.assign', label: 'Assign Contacts', description: 'Assign visible Contacts to a CRM user and/or Team.'),
            new AccessCapabilityDefinition(key: 'contacts.export', label: 'Export Contacts', description: 'Export visible Contact result sets when an export surface is available.'),
            new AccessCapabilityDefinition(key: 'settings.manage', label: 'Manage settings', description: 'Change shared CRM settings when a settings surface requires this capability.'),
            new AccessCapabilityDefinition(key: 'team.manage', label: 'Manage Team access', description: 'Create CRM users and Teams, change roles, membership, and active access.'),
        ];
    }
}