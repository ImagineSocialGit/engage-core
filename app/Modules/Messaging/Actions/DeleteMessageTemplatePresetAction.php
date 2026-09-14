<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\MessageTemplateDeletionReference;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplateDeletionReferenceRegistry;
use App\Modules\Messaging\Services\MessageTemplateUsageResolver;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DeleteMessageTemplatePresetAction
{
    public function __construct(
        private readonly MessageTemplateUsageResolver $usageResolver,
        private readonly MessageTemplateDeletionReferenceRegistry $referenceRegistry,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     source: string|null,
     *     canonical_template_archived: bool
     * }
     */
    public function handle(MessageTemplatePreset $preset): array
    {
        return DB::transaction(function () use ($preset): array {
            $lockedPreset = MessageTemplatePreset::query()
                ->whereKey($preset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $template = MessageTemplate::query()
                ->where('key', $lockedPreset->key)
                ->lockForUpdate()
                ->first();

            $references = $this->blockingReferences($lockedPreset, $template);

            if ($references->isNotEmpty()) {
                throw new InvalidArgumentException(
                    $this->blockedMessage($lockedPreset, $references),
                );
            }

            $result = [
                'key' => (string) $lockedPreset->key,
                'name' => (string) $lockedPreset->name,
                'source' => is_string($lockedPreset->source)
                    ? $lockedPreset->source
                    : null,
                'canonical_template_archived' => false,
            ];

            $lockedPreset->delete();

            if ($template instanceof MessageTemplate
                && $template->status !== MessageTemplate::STATUS_ARCHIVED
            ) {
                $template->forceFill([
                    'status' => MessageTemplate::STATUS_ARCHIVED,
                ])->save();

                $result['canonical_template_archived'] = true;
            }

            return $result;
        }, 3);
    }

    /**
     * @return Collection<int, MessageTemplateDeletionReference>
     */
    private function blockingReferences(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): Collection {
        return $this->assignmentReferences($preset)
            ->concat($this->referenceRegistry->references($preset, $template))
            ->unique(fn (MessageTemplateDeletionReference $reference): string => $reference->fingerprint())
            ->values();
    }

    /**
     * @return Collection<int, MessageTemplateDeletionReference>
     */
    private function assignmentReferences(MessageTemplatePreset $preset): Collection
    {
        $owners = $preset->catalogEntries()
            ->active()
            ->get(['module_key', 'module_label'])
            ->filter(fn ($entry): bool => is_string($entry->module_key) && trim($entry->module_key) !== '')
            ->values();
        $enabled = $this->modules->enabledKeysWithDependencies();
        $campaignAssignmentIds = $preset->assignments()
            ->active()
            ->where('surface', 'campaigns')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $this->usageResolver
            ->forPreset($preset)
            ->reject(fn (array $row): bool => in_array(
                (int) ($row['assignment_id'] ?? 0),
                $campaignAssignmentIds,
                true,
            ))
            ->map(fn (array $row): MessageTemplateDeletionReference =>
                new MessageTemplateDeletionReference(
                    moduleKey: $this->moduleKeyForUsage($row, $owners),
                    moduleLabel: (string) ($row['module_label'] ?? 'Messaging'),
                    contextLabel: (string) ($row['context_label'] ?? 'Template selection'),
                    detail: (string) ($row['item_label'] ?? 'An active selection still uses this template.'),
                    url: is_string($row['url'] ?? null) ? $row['url'] : null,
                )
            )
            ->reject(fn (MessageTemplateDeletionReference $reference): bool =>
                $preset->source === 'config'
                && $reference->moduleKey !== 'messaging'
                && ! in_array($reference->moduleKey, $enabled, true)
            )
            ->values();
    }

    /**
     * @param array<string, mixed> $usage
     * @param Collection<int, mixed> $owners
     */
    private function moduleKeyForUsage(array $usage, Collection $owners): string
    {
        $moduleLabel = trim((string) ($usage['module_label'] ?? ''));

        $owner = $owners->first(
            fn ($entry): bool =>
                is_string($entry->module_label)
                && trim($entry->module_label) === $moduleLabel,
        );

        if ($owner !== null
            && is_string($owner->module_key)
            && trim($owner->module_key) !== ''
        ) {
            return trim($owner->module_key);
        }

        return 'messaging';
    }

    /**
     * @param Collection<int, MessageTemplateDeletionReference> $references
     */
    private function blockedMessage(
        MessageTemplatePreset $preset,
        Collection $references,
    ): string {
        $summaries = $references
            ->take(5)
            ->map(fn (MessageTemplateDeletionReference $reference): string => $reference->summary())
            ->implode('; ');

        $remaining = max(0, $references->count() - 5);
        $suffix = $remaining > 0
            ? '; plus '.number_format($remaining).' more reference'.($remaining === 1 ? '' : 's')
            : '';

        return sprintf(
            'Message template [%s] cannot be deleted while it is still in use. Remove or replace these references first: %s%s.',
            $preset->name,
            $summaries,
            $suffix,
        );
    }
}