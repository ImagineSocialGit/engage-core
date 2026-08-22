<?php

namespace App\Modules\Relationships\Automation;

use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Validation\ValidationException;

class RelationshipStageAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    private const TARGET_SEPARATOR = '::';

    public function __construct(
        private readonly RelationshipDefinitionRegistry $relationships,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'change_relationship_stage',
            moduleKey: 'relationships',
            name: 'Change relationship stage',
            description: 'Move an existing active business relationship for this Contact to another configured stage.',
            tip: 'This only changes an existing active relationship. It never creates or reactivates a relationship implicitly.',
            useCases: [
                'Move a Realtor from Target Agent to Engaged Agent after a positive reply.',
                'Advance a collaborator relationship without changing the Contact sales lifecycle.',
            ],
            typeLabel: 'Relationship',
            genericLabels: ['change relationship stage'],
            generatedPrefixes: ['change relationship stage:'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $pointType === 'change_relationship_stage'
            && $this->targetOptions() !== [];
    }

    public function fields(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): array {
        return [[
            'type' => 'select',
            'name' => 'relationship_stage_target',
            'label' => 'Relationship stage',
            'required' => true,
            'value' => $this->targetValue(
                (string) ($definition['relationship_key'] ?? ''),
                (string) ($definition['stage_key'] ?? ''),
            ),
            'placeholder' => 'Choose a relationship stage',
            'help' => 'Only configured active stages are shown. The Contact must already have that active relationship when this Point runs.',
            'options' => $this->targetOptions(),
        ]];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'relationship_stage_target' => ['required', 'string', 'max:511'],
        ];
    }

    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array {
        [$relationshipKey, $stageKey] = $this->parseTarget(
            (string) ($input['relationship_stage_target'] ?? ''),
        );

        $relationship = $this->relationships->visible()[$relationshipKey] ?? null;
        $stage = is_array($relationship)
            ? ($relationship['stages'][$stageKey] ?? null)
            : null;

        if (! is_array($relationship)
            || ! is_array($stage)
            || ! (bool) ($stage['active'] ?? false)
        ) {
            throw ValidationException::withMessages([
                'relationship_stage_target' => 'Choose an active stage for a visible Contact relationship.',
            ]);
        }

        return [
            'relationship_key' => $relationshipKey,
            'stage_key' => $stageKey,
            'on_missing_relationship' => 'skipped',
        ];
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

        return 'Change relationship stage: '.$this->targetLabel($definition);
    }

    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Change relationship stage: '.$this->targetLabel($definition).'.';
    }

    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Set '.$this->targetLabel($definition);
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    private function targetOptions(): array
    {
        $options = [];

        foreach ($this->relationships->visible() as $relationshipKey => $relationship) {
            foreach ($relationship['stages'] as $stageKey => $stage) {
                if (! (bool) ($stage['active'] ?? false)) {
                    continue;
                }

                $options[] = [
                    'value' => $this->targetValue($relationshipKey, $stageKey),
                    'label' => $relationship['singular'].' — '.$stage['label'],
                    'description' => 'Set the existing '.$relationship['singular'].' relationship to '.$stage['label'].'.',
                ];
            }
        }

        return $options;
    }

    private function targetValue(string $relationshipKey, string $stageKey): string
    {
        if ($relationshipKey === '' || $stageKey === '') {
            return '';
        }

        return $relationshipKey.self::TARGET_SEPARATOR.$stageKey;
    }

    /** @return array{0: string, 1: string} */
    private function parseTarget(string $value): array
    {
        $parts = explode(self::TARGET_SEPARATOR, trim($value), 2);

        if (count($parts) !== 2
            || trim($parts[0]) === ''
            || trim($parts[1]) === ''
        ) {
            throw ValidationException::withMessages([
                'relationship_stage_target' => 'Choose a relationship stage.',
            ]);
        }

        return [trim($parts[0]), trim($parts[1])];
    }

    /** @param array<string, mixed> $definition */
    private function targetLabel(array $definition): string
    {
        $relationshipKey = trim((string) ($definition['relationship_key'] ?? ''));
        $stageKey = trim((string) ($definition['stage_key'] ?? ''));
        $relationship = $relationshipKey !== ''
            ? ($this->relationships->all()[$relationshipKey] ?? null)
            : null;
        $stage = is_array($relationship) && $stageKey !== ''
            ? ($relationship['stages'][$stageKey] ?? null)
            : null;

        if (is_array($relationship) && is_array($stage)) {
            return $relationship['singular'].' → '.$stage['label'];
        }

        if ($relationshipKey !== '' && $stageKey !== '') {
            return $relationshipKey.' → '.$stageKey;
        }

        return $relationshipKey !== ''
            ? $relationshipKey
            : ($stageKey !== '' ? $stageKey : 'selected relationship stage');
    }
}