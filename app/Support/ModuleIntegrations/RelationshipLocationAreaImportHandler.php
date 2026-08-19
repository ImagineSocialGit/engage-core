<?php

namespace App\Support\ModuleIntegrations;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Relationships\Import\ContactRelationshipImportHandler;
use App\Modules\Relationships\Models\ContactRelationship;
use InvalidArgumentException;
use LogicException;

final class RelationshipLocationAreaImportHandler implements ContactImportHandler
{
    public function __construct(
        private readonly ContactRelationshipImportHandler $relationshipImports,
        private readonly RelationshipLocationAreaBridge $bridge,
    ) {}

    public function handle(ContactImportContext $context): void
    {
        $areaKey = $context->value('relationship_location_area_key');

        if ($areaKey === null) {
            return;
        }

        if (! $this->bridge->available()) {
            throw new LogicException(
                'Relationship Location area import requires explicitly enabled Location support.',
            );
        }

        $relationshipKey = $context->value('relationship_key');

        if ($relationshipKey === null) {
            throw new InvalidArgumentException(
                'Relationship Location area import requires a mapped Relationship Type Key.',
            );
        }

        $this->relationshipImports->handle($context);

        $relationship = ContactRelationship::query()
            ->where('contact_id', $context->contact->id)
            ->where('relationship_key', $relationshipKey)
            ->firstOrFail();

        $area = LocationArea::query()
            ->where('key', $areaKey)
            ->where('status', LocationArea::STATUS_ACTIVE)
            ->first();

        if ($area === null) {
            throw new InvalidArgumentException(
                "Imported Location area key [{$areaKey}] does not match an active Location area.",
            );
        }

        $this->bridge->assign(
            relationship: $relationship,
            area: $area,
            isPrimary: $this->boolean(
                $context->value('relationship_location_area_primary'),
                default: true,
            ),
            source: $context->occurrence->original_source ?? 'crm_import',
        );
    }

    private function boolean(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => throw new InvalidArgumentException(
                'Imported field [relationship_location_area_primary] must be Yes or No.',
            ),
        };
    }
}