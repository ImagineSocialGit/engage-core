<?php

namespace App\Modules\Relationships\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Data\ChangeContactRelationshipStageResult;
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
        return $this->handleGuarded(
            contact: $contact,
            relationshipKey: $relationshipKey,
            stageKey: $stageKey,
        )->relationship;
    }

    public function handleGuarded(
        Contact $contact,
        string $relationshipKey,
        string $stageKey,
        ?string $fromStageKey = null,
    ): ChangeContactRelationshipStageResult {
        $relationshipKey = trim($relationshipKey);
        $stageKey = trim($stageKey);
        $fromStageKey = $this->nullableString($fromStageKey);
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

        if ($fromStageKey !== null
            && ! isset($definition['stages'][$fromStageKey])
        ) {
            throw new InvalidArgumentException(
                "Unknown current-stage guard [{$fromStageKey}] for Contact relationship [{$relationshipKey}].",
            );
        }

        return DB::transaction(function () use (
            $contact,
            $relationshipKey,
            $stageKey,
            $fromStageKey,
        ): ChangeContactRelationshipStageResult {
            $relationship = ContactRelationship::query()
                ->where('contact_id', $contact->getKey())
                ->where('relationship_key', $relationshipKey)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $relationship instanceof ContactRelationship) {
                return new ChangeContactRelationshipStageResult(
                    relationship: null,
                    previousStageKey: null,
                    guardMatched: false,
                    changed: false,
                );
            }

            $previousStageKey = $relationship->stage_key;

            if ($fromStageKey !== null
                && $previousStageKey !== $fromStageKey
            ) {
                return new ChangeContactRelationshipStageResult(
                    relationship: $relationship->refresh(),
                    previousStageKey: $previousStageKey,
                    guardMatched: false,
                    changed: false,
                );
            }

            $changed = $previousStageKey !== $stageKey;

            if ($changed) {
                $relationship->stage_key = $stageKey;
                $relationship->save();
            }

            return new ChangeContactRelationshipStageResult(
                relationship: $relationship->refresh(),
                previousStageKey: $previousStageKey,
                guardMatched: true,
                changed: $changed,
            );
        }, 3);
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}