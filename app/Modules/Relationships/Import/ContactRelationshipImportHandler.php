<?php

namespace App\Modules\Relationships\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Models\ContactRelationship;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class ContactRelationshipImportHandler implements ContactImportHandler
{
    public function __construct(
        private readonly UpsertContactRelationshipAction $upsertRelationship,
    ) {}

    public function handle(ContactImportContext $context): void
    {
        $relationshipKey = $context->mappedValue('relationship_key');

        if ($relationshipKey === null) {
            return;
        }

        $existing = ContactRelationship::query()
            ->where('contact_id', $context->contact->id)
            ->where('relationship_key', $relationshipKey)
            ->first(['id', 'source', 'subsource']);

        $this->upsertRelationship->handle(
            contact: $context->contact,
            relationshipKey: $relationshipKey,
            stageKey: $context->mappedValue('relationship_stage'),
            source: $this->importSource(
                current: $existing?->source,
                incoming: $context->mappedValue('relationship_source')
                    ?? $context->occurrence->original_source,
            ),
            subsource: $this->importSubsource(
                current: $existing?->subsource,
                incoming: $context->mappedValue('relationship_subsource')
                    ?? $context->occurrence->original_subsource,
            ),
            active: true,
            startedAt: $this->dateTime(
                $context->mappedValue('relationship_started_at'),
                'relationship_started_at',
            ),
        );
    }

    private function importSource(?string $current, ?string $incoming): ?string
    {
        $current = $this->nonEmptyString($current);

        if ($current !== null && ! in_array(strtolower($current), ['import', 'crm_csv'], true)) {
            return null;
        }

        return $this->nonEmptyString($incoming);
    }

    private function importSubsource(?string $current, ?string $incoming): ?string
    {
        if ($this->nonEmptyString($current) !== null) {
            return null;
        }

        return $this->nonEmptyString($incoming);
    }

    private function nonEmptyString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function dateTime(?string $value, string $field): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                "Imported field [{$field}] must contain a valid date/time.",
                previous: $exception,
            );
        }
    }
}