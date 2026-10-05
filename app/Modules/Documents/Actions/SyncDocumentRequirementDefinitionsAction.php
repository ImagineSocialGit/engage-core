<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Services\DocumentRequirementDefinitionRegistry;
use App\Support\ModuleIntegrations\Documents\Data\DocumentRequirementDefinitionContribution;

final class SyncDocumentRequirementDefinitionsAction
{
    public function __construct(
        private readonly DocumentRequirementDefinitionRegistry $registry,
    ) {}

    /**
     * @return array{
     *     created:int,
     *     updated:int,
     *     unchanged:int,
     *     restored:int,
     *     preserved:int
     * }
     */
    public function handle(): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'restored' => 0,
            'preserved' => 0,
        ];

        foreach ($this->registry->definitions() as $entry) {
            $definition = $entry['definition'];
            $managedSource = 'contributor:'.$entry['contributor'];
            $attributes = $this->attributes($definition, $managedSource);

            $existing = DocumentRequirementDefinition::withTrashed()
                ->where('key', $definition->key)
                ->first();

            if (! $existing instanceof DocumentRequirementDefinition) {
                DocumentRequirementDefinition::query()->create([
                    'key' => $definition->key,
                    ...$attributes,
                ]);
                $result['created']++;

                continue;
            }

            if ($existing->source !== $managedSource) {
                $result['preserved']++;

                continue;
            }

            $restored = $existing->trashed();

            if ($restored) {
                $existing->restore();
                $result['restored']++;
            }

            $existing->fill($attributes);

            if ($existing->isDirty()) {
                $existing->save();
                $result['updated']++;
            } elseif (! $restored) {
                $result['unchanged']++;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(
        DocumentRequirementDefinitionContribution $definition,
        string $source,
    ): array {
        return [
            'name' => trim($definition->name),
            'description' => $this->nullableString($definition->description),
            'instructions' => $this->nullableString($definition->instructions),
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'category' => $this->nullableString($definition->category),
            'is_required_by_default' => $definition->isRequiredByDefault,
            'allows_multiple_uploads' => $definition->allowsMultipleUploads,
            'requires_review' => $definition->requiresReview,
            'accepted_mime_types' => $definition->acceptedMimeTypes === null
                ? null
                : array_values(array_unique(array_map(
                    static fn (string $mimeType): string =>
                        strtolower(trim($mimeType)),
                    $definition->acceptedMimeTypes,
                ))),
            'max_file_size_kb' => $definition->maxFileSizeKb,
            'expires_after_days' => $definition->expiresAfterDays,
            'sort_order' => $definition->sortOrder,
            'source' => $source,
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'settings' => $definition->settings !== []
                ? $definition->settings
                : null,
            'meta' => $definition->meta !== []
                ? $definition->meta
                : null,
        ];
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}