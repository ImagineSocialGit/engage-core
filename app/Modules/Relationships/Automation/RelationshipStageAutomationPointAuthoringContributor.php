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
            tip: 'Optionally limit the change to one current stage so an already-advanced relationship is never moved backward accidentally.',
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
        $relationshipKey = (string) ($definition['relationship_key'] ?? '');

        return [
            [
                'type' => 'select',
                'name' => 'relationship_stage_target',
                'label' => 'Relationship stage',
                'required' => true,
                'value' => $this->targetValue(
                    $relationshipKey,
                    (string) ($definition['stage_key'] ?? ''),
                ),
                'placeholder' => 'Choose a relationship stage',
                'help' => 'Only configured active stages are shown. The Contact must already have that active relationship when this Point runs.',
                'options' => $this->targetOptions(),
            ],
            [
                'type' => 'select',
                'name' => 'relationship_stage_from',
                'label' => 'Only when current stage is',
                'required' => false,
                'value' => $this->targetValue(
                    $relationshipKey,
                    (string) ($definition['from_stage_key'] ?? ''),
                ),
                'placeholder' => 'Any current stage',
                'help' => 'Optional safeguard. If the relationship has already advanced to another stage, this Point is skipped without changing it.',
                'options' => $this->targetOptions(),
            ],
        ];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'relationship_stage_target' => ['required', 'string', 'max:511'],
            'relationship_stage_from' => ['nullable', 'string', 'max:511'],
        ];
    }

    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array {
        [$relationshipKey, $stageKey] = $this->parseTarget(
            value: (string) ($input['relationship_stage_target'] ?? ''),
            field: 'relationship_stage_target',
            message: 'Choose a relationship stage.',
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

        $fromStageKey = $this->resolveFromStageKey(
            relationshipKey: $relationshipKey,
            relationship: $relationship,
            value: (string) ($input['relationship_stage_from'] ?? ''),
        );

        $definition = [
            'relationship_key' => $relationshipKey,
            'stage_key' => $stageKey,
            'on_missing_relationship' => 'skipped',
        ];

        if ($fromStageKey !== null) {
            $definition['from_stage_key'] = $fromStageKey;
        }

        return $definition;
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

        return 'Change relationship stage: '.$this->targetLabel($definition).$this->guardLabel($definition);
    }

    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Change relationship stage: '.$this->targetLabel($definition).$this->guardLabel($definition).'.';
    }

    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Set '.$this->targetLabel($definition).$this->guardLabel($definition);
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
    private function parseTarget(string $value, string $field, string $message): array
    {
        $parts = explode(self::TARGET_SEPARATOR, trim($value), 2);

        if (count($parts) !== 2
            || trim($parts[0]) === ''
            || trim($parts[1]) === ''
        ) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return [trim($parts[0]), trim($parts[1])];
    }

    /**
     * @param array<string, mixed> $relationship
     */
    private function resolveFromStageKey(
        string $relationshipKey,
        array $relationship,
        string $value,
    ): ?string {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        [$fromRelationshipKey, $fromStageKey] = $this->parseTarget(
            value: $value,
            field: 'relationship_stage_from',
            message: 'Choose a current relationship stage.',
        );

        if ($fromRelationshipKey !== $relationshipKey) {
            throw ValidationException::withMessages([
                'relationship_stage_from' => 'The current-stage safeguard must use the same Contact relationship as the target stage.',
            ]);
        }

        $fromStage = $relationship['stages'][$fromStageKey] ?? null;

        if (! is_array($fromStage)
            || ! (bool) ($fromStage['active'] ?? false)
        ) {
            throw ValidationException::withMessages([
                'relationship_stage_from' => 'Choose an active current stage for the selected Contact relationship.',
            ]);
        }

        return $fromStageKey;
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

    /** @param array<string, mixed> $definition */
    private function guardLabel(array $definition): string
    {
        $relationshipKey = trim((string) ($definition['relationship_key'] ?? ''));
        $fromStageKey = trim((string) ($definition['from_stage_key'] ?? ''));

        if ($fromStageKey === '') {
            return '';
        }

        $relationship = $relationshipKey !== ''
            ? ($this->relationships->all()[$relationshipKey] ?? null)
            : null;
        $stage = is_array($relationship)
            ? ($relationship['stages'][$fromStageKey] ?? null)
            : null;
        $label = is_array($stage)
            ? (string) ($stage['label'] ?? $fromStageKey)
            : $fromStageKey;

        return ' only when current stage is '.$label;
    }
}