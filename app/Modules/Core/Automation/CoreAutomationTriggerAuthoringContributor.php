<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class CoreAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const CONTACT_STATUS = 'core.contact_status_changed';
    public const CONTACT_CREATED = 'core.contact_created';
    public const CONTACT_IMPORTED = 'core.contact_imported';
    public const CONTACT_TAG_ADDED = 'core.contact_tag_added';

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::CONTACT_STATUS,
            moduleKey: 'core',
            name: 'Contact moves to a status',
            description: 'Run when a contact enters one selected lifecycle status.',
            sortOrder: 10,
        );

        yield new AutomationTriggerAuthoringDefinition(
            key: self::CONTACT_CREATED,
            moduleKey: 'core',
            name: 'Contact is added manually',
            description: 'Run after a person creates a new contact in the CRM.',
            sortOrder: 20,
        );

        yield new AutomationTriggerAuthoringDefinition(
            key: self::CONTACT_IMPORTED,
            moduleKey: 'core',
            name: 'Contact is imported',
            description: 'Run for each contact included in a completed import row.',
            sortOrder: 30,
        );

        yield new AutomationTriggerAuthoringDefinition(
            key: self::CONTACT_TAG_ADDED,
            moduleKey: 'core',
            name: 'Tag is added to a contact',
            description: 'Run when the selected tag is added to a contact.',
            sortOrder: 40,
        );
    }

    public function available(string $authoringKey): bool
    {
        return match ($authoringKey) {
            self::CONTACT_STATUS => ContactStatus::query()->active()->exists(),
            self::CONTACT_CREATED, self::CONTACT_IMPORTED => true,
            self::CONTACT_TAG_ADDED => ContactTag::query()->whereNotNull('tag')->exists(),
            default => false,
        };
    }

    public function fields(string $authoringKey): array
    {
        if ($authoringKey === self::CONTACT_STATUS) {
            return [[
                'type' => 'select',
                'name' => 'contact_status_id',
                'label' => 'Contact status',
                'required' => true,
                'placeholder' => 'Choose a status',
                'options' => ContactStatus::query()
                    ->active()
                    ->ordered()
                    ->get(['id', 'key', 'name'])
                    ->map(fn (ContactStatus $status): array => [
                        'value' => (string) $status->getKey(),
                        'key' => (string) $status->key,
                        'label' => (string) $status->name,
                    ])
                    ->all(),
            ]];
        }

        if ($authoringKey === self::CONTACT_TAG_ADDED) {
            return [[
                'type' => 'select',
                'name' => 'contact_tag',
                'label' => 'Contact tag',
                'required' => true,
                'placeholder' => 'Choose a tag',
                'options' => ContactTag::query()
                    ->whereNotNull('tag')
                    ->where('tag', '!=', '')
                    ->distinct()
                    ->orderBy('tag')
                    ->pluck('tag')
                    ->map(fn (mixed $tag): array => [
                        'value' => (string) $tag,
                        'label' => (string) $tag,
                    ])
                    ->all(),
                'help' => 'Only tags already used in this CRM are shown.',
            ]];
        }

        return [];
    }

    public function rules(string $authoringKey): array
    {
        return match ($authoringKey) {
            self::CONTACT_STATUS => [
                'contact_status_id' => [
                    'required',
                    'integer',
                    Rule::exists('contact_statuses', 'id')->where(
                        fn ($query) => $query->where('is_active', true),
                    ),
                ],
            ],
            self::CONTACT_TAG_ADDED => [
                'contact_tag' => ['required', 'string', 'max:255', Rule::exists('contact_tags', 'tag')],
            ],
            default => [],
        };
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        if ($authoringKey === self::CONTACT_STATUS) {
            $status = ContactStatus::query()->active()->findOrFail((int) $input['contact_status_id']);

            return new AutomationTriggerSelection(
                triggerType: 'contact_status',
                triggerKey: (string) $status->key,
                contactStatusId: (int) $status->getKey(),
            );
        }

        if ($authoringKey === self::CONTACT_TAG_ADDED) {
            return new AutomationTriggerSelection(
                triggerType: 'automation_event',
                triggerKey: 'contact.tag_added',
                entryConditions: [$this->condition(
                    'automation_event.payload.contact_tag.tag',
                    trim((string) $input['contact_tag']),
                )],
            );
        }

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: $authoringKey === self::CONTACT_IMPORTED
                ? 'contact.imported'
                : 'contact.created',
        );
    }

    /** @return array<string, mixed> */
    private function condition(string $path, mixed $value): array
    {
        return [
            'source' => 'execution_meta',
            'path' => $path,
            'operator' => 'equals',
            'value' => $value,
        ];
    }
}