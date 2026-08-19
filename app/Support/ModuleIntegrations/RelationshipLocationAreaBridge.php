<?php

namespace App\Support\ModuleIntegrations;

use App\Modules\Location\Actions\AssignSubjectToLocationAreaAction;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\Modules\ModuleManager;
use LogicException;

class RelationshipLocationAreaBridge
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly AssignSubjectToLocationAreaAction $assignArea,
    ) {}

    public function available(): bool
    {
        return in_array('relationships', $this->modules->enabledKeysWithDependencies(), true)
            && $this->modules->enabled('location');
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function assign(
        ContactRelationship $relationship,
        LocationArea $area,
        bool $isPrimary = false,
        string $role = LocationAreaAssignment::ROLE_MEMBER,
        string $source = 'manual',
        ?array $meta = null,
    ): LocationAreaAssignment {
        if (! $this->available()) {
            throw new LogicException(
                'Relationship Location area composition requires Relationships and explicitly enabled Location modules.',
            );
        }

        $relationship->loadMissing('contact');

        return $this->assignArea->handle(
            area: $area,
            subject: $relationship,
            contact: $relationship->contact,
            role: $role,
            isPrimary: $isPrimary,
            source: $source,
            meta: $meta,
        );
    }
}