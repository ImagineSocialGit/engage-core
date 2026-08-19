<?php

namespace App\Modules\Relationships\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpsertContactRelationshipAction
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
    ) {}

    /**
     * @param array<string, mixed>|null $meta
     */
    public function handle(
        Contact $contact,
        string $relationshipKey,
        ?string $stageKey = null,
        ?string $source = null,
        ?string $subsource = null,
        bool $active = true,
        ?DateTimeInterface $startedAt = null,
        ?array $meta = null,
    ): ContactRelationship {
        $relationshipKey = trim($relationshipKey);
        $this->definitions->get($relationshipKey);

        $stageKey = $this->nullableString($stageKey);

        if ($stageKey !== null
            && ! $this->definitions->stageExists($relationshipKey, $stageKey)
        ) {
            throw new InvalidArgumentException(
                "Unknown stage [{$stageKey}] for Contact relationship [{$relationshipKey}].",
            );
        }

        return DB::transaction(function () use (
            $contact,
            $relationshipKey,
            $stageKey,
            $source,
            $subsource,
            $active,
            $startedAt,
            $meta,
        ): ContactRelationship {
            $relationship = ContactRelationship::query()
                ->where('contact_id', $contact->getKey())
                ->where('relationship_key', $relationshipKey)
                ->lockForUpdate()
                ->first();

            if ($relationship === null) {
                $relationship = new ContactRelationship([
                    'contact_id' => $contact->getKey(),
                    'relationship_key' => $relationshipKey,
                    'started_at' => $startedAt ?? now(),
                ]);
            }

            if ($stageKey !== null || ! $relationship->exists) {
                $relationship->stage_key = $stageKey;
            }

            $normalizedSource = $this->nullableString($source);
            $normalizedSubsource = $this->nullableString($subsource);

            if ($normalizedSource !== null || ! $relationship->exists) {
                $relationship->source = $normalizedSource;
            }

            if ($normalizedSubsource !== null || ! $relationship->exists) {
                $relationship->subsource = $normalizedSubsource;
            }

            $relationship->is_active = $active;
            $relationship->ended_at = $active ? null : now();

            if ($relationship->started_at === null) {
                $relationship->started_at = $startedAt ?? now();
            }

            if ($meta !== null) {
                $relationship->meta = $meta;
            }

            $relationship->save();

            return $relationship->refresh();
        });
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