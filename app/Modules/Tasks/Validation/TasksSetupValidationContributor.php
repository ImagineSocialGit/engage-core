<?php

namespace App\Modules\Tasks\Validation;

use App\Modules\Tasks\Data\TaskPresetDefinition;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

class TasksSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'presets.tasks';
    private const MODULE = 'tasks';

    private const CLIENT_FACING_NOUNS = [
        'lead',
        'leads',
        'fan',
        'fans',
        'customer',
        'customers',
        'borrower',
        'borrowers',
        'member',
        'members',
        'owner',
        'owners',
        'client',
        'clients',
    ];

    private const FIRST_CLASS_DEFAULT_FIELDS = [
        'title',
        'description',
        'assigned_to_type',
        'assigned_to_id',
        'assigned_to_strategy',
        'responsible_party',
        'responsible_type',
        'responsible_id',
        'priority',
        'due_offset_minutes',
    ];

    public function findings(): iterable
    {
        $presetKey = $this->selectedPresetKey();

        if ($presetKey === null) {
            return;
        }

        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
            return;
        }

        $groups = $package['groups']['tasks'] ?? [];

        if (! is_array($groups)) {
            yield $this->error(
                code: 'tasks.selected_groups_invalid',
                message: "Preset package [{$presetKey}] groups.tasks must be an array.",
                path: "presets.packages.{$presetKey}.groups.tasks",
                context: [
                    'preset_key' => $presetKey,
                ],
            );

            return;
        }

        $seenTemplateGroups = [];

        foreach ($groups as $index => $groupKey) {
            if (! $this->filledString($groupKey)) {
                yield $this->error(
                    code: 'tasks.selected_group_key_invalid',
                    message: 'Selected Task preset group keys must be non-empty strings.',
                    path: "presets.packages.{$presetKey}.groups.tasks.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                    ],
                );

                continue;
            }

            $groupKey = trim($groupKey);
            $groupPath = "presets.tasks.groups.{$groupKey}";
            $group = config($groupPath);

            if (! is_array($group)) {
                yield $this->error(
                    code: 'tasks.group_missing',
                    message: "Selected Task preset group [{$groupKey}] does not exist.",
                    path: $groupPath,
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            if (array_is_list($group)) {
                yield from $this->validateReferencedGroup(
                    presetKey: $presetKey,
                    groupKey: $groupKey,
                    templateKeys: $group,
                    seenTemplateGroups: $seenTemplateGroups,
                );

                continue;
            }

            $legacyTemplates = $group['templates'] ?? null;

            if (! is_array($legacyTemplates)) {
                yield $this->error(
                    code: 'tasks.group_shape_invalid',
                    message: "Task preset group [{$groupKey}] must be a list of TaskTemplate keys.",
                    path: $groupPath,
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            yield $this->warning(
                code: 'tasks.legacy_group_shape',
                message: "Task preset group [{$groupKey}] uses the tolerated legacy [templates] shape instead of stable definition references.",
                path: "{$groupPath}.templates",
                context: [
                    'preset_key' => $presetKey,
                    'group_key' => $groupKey,
                ],
            );

            foreach ($legacyTemplates as $templateIndex => $definition) {
                if (! is_array($definition)) {
                    yield $this->error(
                        code: 'tasks.definition_invalid',
                        message: "Task preset group [{$groupKey}] contains a non-array template definition.",
                        path: "{$groupPath}.templates.{$templateIndex}",
                        context: [
                            'preset_key' => $presetKey,
                            'group_key' => $groupKey,
                        ],
                    );

                    continue;
                }

                $templateKey = $definition['key'] ?? null;

                if (! $this->filledString($templateKey)) {
                    yield $this->error(
                        code: 'tasks.definition_key_missing',
                        message: "Legacy TaskTemplate definition in group [{$groupKey}] is missing a non-empty [key].",
                        path: "{$groupPath}.templates.{$templateIndex}.key",
                        context: [
                            'preset_key' => $presetKey,
                            'group_key' => $groupKey,
                        ],
                    );

                    continue;
                }

                $templateKey = trim($templateKey);

                yield from $this->validateAmbiguousSelection(
                    presetKey: $presetKey,
                    groupKey: $groupKey,
                    templateKey: $templateKey,
                    path: "{$groupPath}.templates.{$templateIndex}.key",
                    seenTemplateGroups: $seenTemplateGroups,
                );

                yield from $this->validateDefinition(
                    presetKey: $presetKey,
                    groupKey: $groupKey,
                    templateKey: $templateKey,
                    definition: $definition,
                    path: "{$groupPath}.templates.{$templateIndex}",
                );
            }
        }
    }

    /**
     * @param array<int|string, mixed> $templateKeys
     * @param array<string, string> $seenTemplateGroups
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateReferencedGroup(
        string $presetKey,
        string $groupKey,
        array $templateKeys,
        array &$seenTemplateGroups,
    ): iterable {
        foreach ($templateKeys as $index => $templateKey) {
            if (! $this->filledString($templateKey)) {
                yield $this->error(
                    code: 'tasks.group_reference_invalid',
                    message: "Task preset group [{$groupKey}] contains an invalid TaskTemplate reference.",
                    path: "presets.tasks.groups.{$groupKey}.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            $templateKey = trim($templateKey);
            $definitionPath = "presets.tasks.definitions.{$templateKey}";

            $definitions = config('presets.tasks.definitions', []);
            $definition = is_array($definitions)
                ? ($definitions[$templateKey] ?? null)
                : null;

            yield from $this->validateAmbiguousSelection(
                presetKey: $presetKey,
                groupKey: $groupKey,
                templateKey: $templateKey,
                path: "presets.tasks.groups.{$groupKey}.{$index}",
                seenTemplateGroups: $seenTemplateGroups,
            );

            if (! is_array($definition)) {
                yield $this->error(
                    code: 'tasks.definition_missing',
                    message: "TaskTemplate preset definition [{$templateKey}] referenced by group [{$groupKey}] does not exist.",
                    path: $definitionPath,
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                        'task_template_key' => $templateKey,
                    ],
                );

                continue;
            }

            yield from $this->validateDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                templateKey: $templateKey,
                definition: $definition,
                path: $definitionPath,
            );
        }
    }

    /**
     * @param array<string, string> $seenTemplateGroups
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateAmbiguousSelection(
        string $presetKey,
        string $groupKey,
        string $templateKey,
        string $path,
        array &$seenTemplateGroups,
    ): iterable {
        $previousGroup = $seenTemplateGroups[$templateKey] ?? null;

        if ($previousGroup !== null && $previousGroup !== $groupKey) {
            yield $this->error(
                code: 'tasks.template_key_ambiguous_across_groups',
                message: "TaskTemplate key [{$templateKey}] is selected by multiple Task preset groups [{$previousGroup}] and [{$groupKey}].",
                path: $path,
                context: [
                    'preset_key' => $presetKey,
                    'task_template_key' => $templateKey,
                    'first_group_key' => $previousGroup,
                    'second_group_key' => $groupKey,
                ],
            );

            return;
        }

        $seenTemplateGroups[$templateKey] = $groupKey;
    }

    /**
     * @param array<string, mixed> $definition
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDefinition(
        string $presetKey,
        string $groupKey,
        string $templateKey,
        array $definition,
        string $path,
    ): iterable {
        $context = [
            'preset_key' => $presetKey,
            'group_key' => $groupKey,
            'task_template_key' => $templateKey,
        ];

        if (array_key_exists('key', $definition)) {
            $definitionKey = $definition['key'];

            if (! $this->filledString($definitionKey)) {
                yield $this->error(
                    code: 'tasks.definition_key_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] has an invalid [key].",
                    path: "{$path}.key",
                    context: $context,
                );
            } elseif (trim($definitionKey) !== $templateKey) {
                yield $this->error(
                    code: 'tasks.definition_key_mismatch',
                    message: "TaskTemplate preset definition [{$templateKey}] key must match its config key [".trim($definitionKey)."].",
                    path: "{$path}.key",
                    context: $context + [
                        'definition_key' => trim($definitionKey),
                    ],
                );
            }
        }

        if ($noun = $this->clientFacingNounInKey($templateKey)) {
            yield $this->error(
                code: 'tasks.noncanonical_identifier',
                message: "TaskTemplate internal key [{$templateKey}] uses client-facing noun [{$noun}] instead of canonical contact terminology.",
                path: $path,
                context: $context + [
                    'client_facing_noun' => $noun,
                ],
            );
        }

        if (! $this->filledString($definition['title'] ?? null)) {
            yield $this->error(
                code: 'tasks.definition_title_missing',
                message: "TaskTemplate preset definition [{$templateKey}] is missing a non-empty [title].",
                path: "{$path}.title",
                context: $context,
            );
        }

        $responsibleParty = $definition['responsible_party'] ?? Task::RESPONSIBLE_PARTY_INTERNAL;

        if (! is_string($responsibleParty) || ! in_array(trim($responsibleParty), Task::RESPONSIBLE_PARTY_OPTIONS, true)) {
            yield $this->error(
                code: 'tasks.responsible_party_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] has an invalid [responsible_party].",
                path: "{$path}.responsible_party",
                context: $context,
            );
        }

        yield from $this->validateAssignmentStrategy(
            templateKey: $templateKey,
            definition: $definition,
            path: $path,
            context: $context,
        );

        yield from $this->validateMorphPairs(
            templateKey: $templateKey,
            definition: $definition,
            path: $path,
            context: $context,
        );

        yield from $this->validateDueAndDefaults(
            templateKey: $templateKey,
            definition: $definition,
            path: $path,
            context: $context,
        );

        yield from $this->validateRelatedSubject(
            templateKey: $templateKey,
            definition: $definition,
            path: $path,
            context: $context,
        );

        if (array_key_exists('meta', $definition) && ! is_array($definition['meta'])) {
            yield $this->error(
                code: 'tasks.meta_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [meta] must be an array.",
                path: "{$path}.meta",
                context: $context,
            );
        }

        $normalizedDefinition = array_replace(['key' => $templateKey], $definition);
        $presetDefinition = TaskPresetDefinition::fromArray($groupKey, $normalizedDefinition);

        if (! $presetDefinition->isValid()) {
            yield $this->error(
                code: 'tasks.definition_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] cannot create a valid TaskTemplate: [{$presetDefinition->invalidReason}].",
                path: $path,
                context: $context + [
                    'invalid_reason' => $presetDefinition->invalidReason,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateAssignmentStrategy(
        string $templateKey,
        array $definition,
        string $path,
        array $context,
    ): iterable {
        $strategy = $definition['assigned_to_strategy'] ?? null;
        $legacyStrategy = $definition['assigned_to'] ?? null;

        if ($strategy !== null && $legacyStrategy !== null && $strategy !== $legacyStrategy) {
            yield $this->error(
                code: 'tasks.assignment_strategy_conflict',
                message: "TaskTemplate preset definition [{$templateKey}] defines conflicting [assigned_to_strategy] and legacy [assigned_to] values.",
                path: "{$path}.assigned_to_strategy",
                context: $context,
            );

            return;
        }

        $resolved = $strategy ?? $legacyStrategy;

        if ($resolved === null) {
            return;
        }

        if (! is_string($resolved) || ! in_array(trim($resolved), TaskTemplate::ASSIGNED_TO_STRATEGIES, true)) {
            yield $this->error(
                code: 'tasks.assignment_strategy_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] has an invalid assignment strategy.",
                path: $strategy !== null
                    ? "{$path}.assigned_to_strategy"
                    : "{$path}.assigned_to",
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateMorphPairs(
        string $templateKey,
        array $definition,
        string $path,
        array $context,
    ): iterable {
        $assignedType = $definition['assigned_to_type'] ?? null;
        $assignedId = $definition['assigned_to_id'] ?? null;

        if ($this->filledString($assignedType) && ! is_numeric($assignedId)) {
            yield $this->error(
                code: 'tasks.assigned_to_morph_incomplete',
                message: "TaskTemplate preset definition [{$templateKey}] defines [assigned_to_type] without a numeric [assigned_to_id].",
                path: "{$path}.assigned_to_id",
                context: $context,
            );
        }

        if ($assignedId !== null && ! is_numeric($assignedId)) {
            yield $this->error(
                code: 'tasks.assigned_to_id_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [assigned_to_id] must be numeric when provided.",
                path: "{$path}.assigned_to_id",
                context: $context,
            );
        }

        $responsibleType = $definition['responsible_type'] ?? null;
        $responsibleId = $definition['responsible_id'] ?? null;
        $hasResponsibleType = $this->filledString($responsibleType);
        $hasResponsibleId = is_numeric($responsibleId);

        if ($hasResponsibleType !== $hasResponsibleId) {
            yield $this->error(
                code: 'tasks.responsible_morph_incomplete',
                message: "TaskTemplate preset definition [{$templateKey}] must define both [responsible_type] and numeric [responsible_id], or neither.",
                path: $hasResponsibleType
                    ? "{$path}.responsible_id"
                    : "{$path}.responsible_type",
                context: $context,
            );
        } elseif ($responsibleId !== null && ! is_numeric($responsibleId)) {
            yield $this->error(
                code: 'tasks.responsible_id_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [responsible_id] must be numeric when provided.",
                path: "{$path}.responsible_id",
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDueAndDefaults(
        string $templateKey,
        array $definition,
        string $path,
        array $context,
    ): iterable {
        if (array_key_exists('due_offset_minutes', $definition)
            && $definition['due_offset_minutes'] !== null
            && ! $this->integerLike($definition['due_offset_minutes'])
        ) {
            yield $this->error(
                code: 'tasks.due_offset_minutes_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [due_offset_minutes] must be an integer when provided.",
                path: "{$path}.due_offset_minutes",
                context: $context,
            );
        }

        if (array_key_exists('due_offset_days', $definition)) {
            if ($definition['due_offset_days'] !== null && ! $this->integerLike($definition['due_offset_days'])) {
                yield $this->error(
                    code: 'tasks.due_offset_days_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] legacy [due_offset_days] must be an integer when provided.",
                    path: "{$path}.due_offset_days",
                    context: $context,
                );
            } else {
                yield $this->warning(
                    code: 'tasks.legacy_due_offset_days',
                    message: "TaskTemplate preset definition [{$templateKey}] uses legacy [due_offset_days]; new definitions should use [due_offset_minutes].",
                    path: "{$path}.due_offset_days",
                    context: $context,
                );
            }
        }

        if (! array_key_exists('defaults', $definition)) {
            return;
        }

        $defaults = $definition['defaults'];

        if (! is_array($defaults)) {
            yield $this->error(
                code: 'tasks.defaults_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [defaults] must be an array.",
                path: "{$path}.defaults",
                context: $context,
            );

            return;
        }

        foreach (self::FIRST_CLASS_DEFAULT_FIELDS as $field) {
            if (! array_key_exists($field, $defaults)) {
                continue;
            }

            yield $this->warning(
                code: 'tasks.first_class_field_duplicated_in_defaults',
                message: "TaskTemplate preset definition [{$templateKey}] duplicates first-class field [{$field}] inside [defaults].",
                path: "{$path}.defaults.{$field}",
                context: $context + [
                    'field' => $field,
                ],
            );
        }

        if (! array_key_exists('due', $defaults)) {
            return;
        }

        $due = $defaults['due'];

        if (! is_array($due)) {
            yield $this->error(
                code: 'tasks.defaults_due_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [defaults.due] must be an array.",
                path: "{$path}.defaults.due",
                context: $context,
            );

            return;
        }

        $type = $due['type'] ?? 'delay';

        if (! is_string($type) || trim($type) !== 'delay') {
            yield $this->error(
                code: 'tasks.defaults_due_type_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [defaults.due.type] must be [delay].",
                path: "{$path}.defaults.due.type",
                context: $context,
            );
        }

        foreach (['minutes', 'hours', 'days'] as $unit) {
            if (! array_key_exists($unit, $due) || $due[$unit] === null) {
                continue;
            }

            if (! $this->integerLike($due[$unit])) {
                yield $this->error(
                    code: 'tasks.defaults_due_unit_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] [defaults.due.{$unit}] must be an integer when provided.",
                    path: "{$path}.defaults.due.{$unit}",
                    context: $context + [
                        'unit' => $unit,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRelatedSubject(
        string $templateKey,
        array $definition,
        string $path,
        array $context,
    ): iterable {
        if (! array_key_exists('related_subject', $definition)) {
            return;
        }

        $relatedSubject = $definition['related_subject'];

        if (! is_array($relatedSubject)) {
            yield $this->error(
                code: 'tasks.related_subject_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [related_subject] must be an array.",
                path: "{$path}.related_subject",
                context: $context,
            );

            return;
        }

        $default = $relatedSubject['default'] ?? null;

        if (! $this->filledString($default)) {
            yield $this->error(
                code: 'tasks.related_subject_default_missing',
                message: "TaskTemplate preset definition [{$templateKey}] [related_subject] requires a non-empty [default].",
                path: "{$path}.related_subject.default",
                context: $context,
            );

            return;
        }

        if (trim($default) !== 'current_contact') {
            yield $this->error(
                code: 'tasks.related_subject_default_unsupported',
                message: "TaskTemplate preset definition [{$templateKey}] uses unsupported related-subject default [".trim($default)."].",
                path: "{$path}.related_subject.default",
                context: $context + [
                    'related_subject_default' => trim($default),
                ],
            );
        }
    }

    private function selectedPresetKey(): ?string
    {
        foreach ([
            config('client.preset'),
            config('presets.default_package'),
        ] as $presetKey) {
            if ($this->filledString($presetKey)) {
                return trim($presetKey);
            }
        }

        return null;
    }

    private function clientFacingNounInKey(string $key): ?string
    {
        $segments = preg_split('/[^a-z0-9]+/', strtolower(trim($key))) ?: [];

        foreach ($segments as $segment) {
            if (in_array($segment, self::CLIENT_FACING_NOUNS, true)) {
                return $segment;
            }
        }

        return null;
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function integerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value)
            && preg_match('/^-?\d+$/', trim($value)) === 1;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
