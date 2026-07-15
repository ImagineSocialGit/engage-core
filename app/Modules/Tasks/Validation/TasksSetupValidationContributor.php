<?php

namespace App\Modules\Tasks\Validation;

use App\Modules\Tasks\Data\TaskPresetDefinition;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Throwable;

class TasksSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'preset_composition.tasks';
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

    public function __construct(
        private readonly PresetCompositionResolver $compositionResolver,
        private readonly PresetPackageResolver $packageResolver,
    ) {}
public function findings(): iterable
    {
        $presetKey = $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        try {
            $resolved = $this->compositionResolver->resolve(
                presetKey: $presetKey,
                domain: PresetDomain::Tasks,
            );
        } catch (Throwable) {
            return;
        }

        foreach ($resolved->definitions as $templateKey => $definition) {
            $groupKey = $resolved->definitionGroups[$templateKey][0] ?? 'selected';
            $source = $resolved->provenance[$templateKey]['source'] ?? self::SOURCE;
            $path = "{$source}.definitions.{$templateKey}";

            yield from $this->validateDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                templateKey: $templateKey,
                definition: $definition,
                path: $path,
            );
        }
    }

    /**
     * @param array<int|string, mixed> $templateKeys
     * @param array<string, string> $seenTemplateGroups
     * @return iterable<int, SetupValidationFinding>
     */

    /**
     * @param array<string, string> $seenTemplateGroups
     * @return iterable<int, SetupValidationFinding>
     */

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

        yield from $this->validateLinkDefaults(
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
        $presetDefinition = TaskPresetDefinition::fromArray($normalizedDefinition);

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

        foreach (['links', 'link_defaults'] as $relationshipField) {
            if (! array_key_exists($relationshipField, $defaults)) {
                continue;
            }

            yield $this->error(
                code: 'tasks.relationship_defaults_not_first_class',
                message: "TaskTemplate preset definition [{$templateKey}] must use first-class [link_defaults], not [defaults.{$relationshipField}].",
                path: "{$path}.defaults.{$relationshipField}",
                context: $context + [
                    'field' => $relationshipField,
                ],
            );
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
    private function validateLinkDefaults(
        string $templateKey,
        array $definition,
        string $path,
        array $context,
    ): iterable {
        if (array_key_exists('related_subject', $definition)) {
            yield $this->error(
                code: 'tasks.related_subject_obsolete',
                message: "TaskTemplate preset definition [{$templateKey}] uses obsolete [related_subject]; use first-class [link_defaults].",
                path: "{$path}.related_subject",
                context: $context,
            );
        }

        if (! array_key_exists('link_defaults', $definition)) {
            return;
        }

        $linkDefaults = $definition['link_defaults'];

        if (! is_array($linkDefaults) || ! array_is_list($linkDefaults)) {
            yield $this->error(
                code: 'tasks.link_defaults_invalid',
                message: "TaskTemplate preset definition [{$templateKey}] [link_defaults] must be a list.",
                path: "{$path}.link_defaults",
                context: $context,
            );

            return;
        }

        $seen = [];

        foreach ($linkDefaults as $index => $linkDefault) {
            $itemPath = "{$path}.link_defaults.{$index}";

            if (! is_array($linkDefault)) {
                yield $this->error(
                    code: 'tasks.link_default_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] link default [{$index}] must be an array.",
                    path: $itemPath,
                    context: $context + [
                        'link_default_index' => $index,
                    ],
                );

                continue;
            }

            $role = $linkDefault['role'] ?? null;
            $source = $linkDefault['source'] ?? null;

            if (! is_string($role)
                || ! in_array(trim($role), TaskLink::ROLES, true)
            ) {
                yield $this->error(
                    code: 'tasks.link_default_role_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] link default [{$index}] has an invalid [role].",
                    path: "{$itemPath}.role",
                    context: $context + [
                        'link_default_index' => $index,
                    ],
                );
            }

            if (! is_string($source)
                || ! in_array(trim($source), TaskTemplate::LINK_SOURCES, true)
            ) {
                yield $this->error(
                    code: 'tasks.link_default_source_invalid',
                    message: "TaskTemplate preset definition [{$templateKey}] link default [{$index}] has an invalid [source].",
                    path: "{$itemPath}.source",
                    context: $context + [
                        'link_default_index' => $index,
                    ],
                );
            }

            if (! is_string($role)
                || ! is_string($source)
                || ! in_array(trim($role), TaskLink::ROLES, true)
                || ! in_array(trim($source), TaskTemplate::LINK_SOURCES, true)
            ) {
                continue;
            }

            $identity = trim($role).':'.trim($source);

            if (isset($seen[$identity])) {
                yield $this->error(
                    code: 'tasks.link_default_duplicate',
                    message: "TaskTemplate preset definition [{$templateKey}] defines duplicate link default [{$identity}].",
                    path: $itemPath,
                    context: $context + [
                        'link_default_index' => $index,
                        'link_default_identity' => $identity,
                    ],
                );

                continue;
            }

            $seen[$identity] = true;
        }
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
