<?php

namespace App\Modules\Relationships\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChangeContactRelationshipStageAction
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
    ) {}

    public function handle(
        Contact $contact,
        string $relationshipKey,
        string $stageKey,
    ): ?ContactRelationship {
        $relationshipKey = trim($relationshipKey);
        $stageKey = trim($stageKey);
        $definition = $this->definitions->get($relationshipKey);
        $stage = $definition['stages'][$stageKey] ?? null;

        if (! is_array($stage)) {
            throw new InvalidArgumentException(
                "Unknown stage [{$stageKey}] for Contact relationship [{$relationshipKey}].",
            );
        }

        if (! (bool) ($stage['active'] ?? false)) {
            throw new InvalidArgumentException(
                "Inactive stage [{$stageKey}] cannot be assigned to Contact relationship [{$relationshipKey}].",
            );
        }

        return DB::transaction(function () use ($contact, $relationshipKey, $stageKey): ?ContactRelationship {
            $relationship = ContactRelationship::query()
                ->where('contact_id', $contact->getKey())
                ->where('relationship_key', $relationshipKey)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $relationship instanceof ContactRelationship) {
                return null;
            }

            if ($relationship->stage_key !== $stageKey) {
                $relationship->stage_key = $stageKey;
                $relationship->save();
            }

            return $relationship->refresh();
        }, 3);
    }
}