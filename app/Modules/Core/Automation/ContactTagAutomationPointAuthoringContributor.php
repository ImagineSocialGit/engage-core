<?php

namespace App\Modules\Core\Automation;

use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Validation\ValidationException;

class ContactTagAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    private const MAX_TAG_LENGTH = 255;

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'add_contact_tag',
            moduleKey: 'core',
            name: 'Add a Contact tag',
            description: 'Add a reusable tag to the Contact when the record reaches this Point.',
            tip: 'Tags are additive CRM facts. Use Contact Status for the primary lifecycle stage and tags for secondary facts or segments.',
            useCases: [
                'Mark a Contact as a webinar attendee without changing lifecycle status.',
                'Record an interest or segment that other automation can use later.',
            ],
            typeLabel: 'Tag',
            genericLabels: ['add contact tag', 'add tag'],
            generatedPrefixes: ['add tag:', 'add contact tag:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: 'remove_contact_tag',
            moduleKey: 'core',
            name: 'Remove a Contact tag',
            description: 'Remove a reusable tag from the Contact when the record reaches this Point.',
            tip: 'Removing a tag is idempotent: the Point succeeds even when the Contact does not currently have that tag.',
            useCases: [
                'Clear a temporary nurture or interest marker.',
                'Remove a segment tag when a Contact moves into a different path.',
            ],
            typeLabel: 'Tag',
            genericLabels: ['remove contact tag', 'remove tag'],
            generatedPrefixes: ['remove tag:', 'remove contact tag:'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return in_array($pointType, ['add_contact_tag', 'remove_contact_tag'], true);
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        return [[
            'type' => 'text',
            'name' => 'tag',
            'label' => 'Contact Tag',
            'required' => true,
            'value' => (string) ($definition['tag'] ?? ''),
            'placeholder' => 'webinar:attended',
            'help' => $pointType === 'remove_contact_tag'
                ? 'Enter the exact tag to remove from the Contact.'
                : 'Enter the tag to add to the Contact. Existing matching tags are not duplicated.',
        ]];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'tag' => ['required', 'string', 'max:'.self::MAX_TAG_LENGTH],
        ];
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $tag = trim((string) ($input['tag'] ?? ''));

        if ($tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH) {
            throw ValidationException::withMessages([
                'tag' => 'Enter a Contact tag no longer than '.self::MAX_TAG_LENGTH.' characters.',
            ]);
        }

        return ['tag' => $tag];
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        $customName = trim((string) ($input['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        $verb = $pointType === 'remove_contact_tag' ? 'Remove tag' : 'Add tag';

        return $verb.': '.(string) ($definition['tag'] ?? '');
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        $verb = $pointType === 'remove_contact_tag' ? 'Remove Contact tag' : 'Add Contact tag';

        return $verb.': '.(string) ($definition['tag'] ?? '').'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        $verb = $pointType === 'remove_contact_tag' ? 'Remove tag' : 'Add tag';

        return $verb.': '.(string) ($definition['tag'] ?? '');
    }
}