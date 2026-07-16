<?php

namespace App\Modules\Messaging\Validation;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use App\Modules\Messaging\Services\MessageConfigValidator;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessagingSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'messaging';

    public function __construct(
        private readonly MessageConfigValidator $messageConfigValidator,
        private readonly ConsentDomainRegistry $consentDomainRegistry,
    ) {}

    public function findings(): iterable
    {
        yield from $this->validateConsentDomains();
        yield from $this->validateConfigRoutes();
        yield from $this->validateCustomizedPresets();
        yield from $this->validateActiveAssignments();
        yield from $this->validateAmbiguousStandardAssignments();
        yield from $this->validateExactAssignmentAmbiguity();
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateConsentDomains(): iterable
    {
        foreach ($this->consentDomainRegistry->validationIssues() as $issue) {
            yield $this->error(
                code: $issue['code'],
                message: $issue['message'],
                source: 'consent_domains',
                path: $issue['path'],
                context: $issue['context'],
            );
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateConfigRoutes(): iterable
    {
        foreach (['email', 'sms'] as $channel) {
            $definitionsPath = MessageDefinitionConfigPath::definitionsRoot($channel);
            $definitionsConfig = config($definitionsPath, []);

            if (! is_array($definitionsConfig)) {
                yield $this->error(
                    code: 'messaging.channel_config_invalid',
                    message: "Messaging definition config for channel [{$channel}] must be an array.",
                    source: $definitionsPath,
                    path: $definitionsPath,
                    context: [
                        'channel' => $channel,
                    ],
                );

                continue;
            }

            foreach (MessagePurpose::values() as $purpose) {
                $purposePath = MessageDefinitionConfigPath::purpose($channel, $purpose);
                $purposeConfig = $definitionsConfig[$purpose] ?? null;

                if ($purposeConfig === null) {
                    continue;
                }

                if (! is_array($purposeConfig)) {
                    yield $this->error(
                        code: 'messaging.purpose_config_invalid',
                        message: "Messaging purpose config [{$channel}.{$purpose}] must be an array.",
                        source: $purposePath,
                        path: $purposePath,
                        context: [
                            'channel' => $channel,
                            'purpose' => $purpose,
                        ],
                    );

                    continue;
                }

                foreach ($purposeConfig as $scope => $scopeConfig) {
                    if (! is_string($scope) || trim($scope) === '') {
                        yield $this->error(
                            code: 'messaging.scope_key_invalid',
                            message: "Messaging purpose config [{$channel}.{$purpose}] contains an invalid scope key.",
                            source: $purposePath,
                            path: $purposePath,
                            context: [
                                'channel' => $channel,
                                'purpose' => $purpose,
                            ],
                        );

                        continue;
                    }

                    $scopePath = MessageDefinitionConfigPath::scope($channel, $purpose, $scope);

                    if (! is_array($scopeConfig)) {
                        yield $this->error(
                            code: 'messaging.scope_config_invalid',
                            message: "Messaging scope config [{$channel}.{$purpose}.{$scope}] must be an array.",
                            source: $scopePath,
                            path: $scopePath,
                            context: [
                                'channel' => $channel,
                                'purpose' => $purpose,
                                'scope' => $scope,
                            ],
                        );

                        continue;
                    }

                    $issues = $this->messageConfigValidator->validateRoute(
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                    );

                    foreach ($issues as $issue) {
                        yield $this->findingFromIssue(
                            issue: $issue,
                            source: $scopePath,
                            context: [
                                'channel' => $channel,
                                'purpose' => $purpose,
                                'scope' => $scope,
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateCustomizedPresets(): iterable
    {
        /** @var Collection<int, MessageTemplatePreset> $presets */
        $presets = MessageTemplatePreset::query()
            ->where('is_customized', true)
            ->with([
                'catalogEntries' => fn ($query) => $query->active()->orderBy('item_order')->orderBy('id'),
            ])
            ->orderBy('key')
            ->get();

        foreach ($presets as $preset) {
            $path = "message_template_presets.{$preset->getKey()}";

            if (! $this->filledString($preset->key)) {
                yield $this->error(
                    code: 'messaging.customized_preset_key_missing',
                    message: "Customized MessageTemplatePreset [{$preset->getKey()}] has no stable key.",
                    source: 'message_template_presets',
                    path: "{$path}.key",
                    context: [
                        'message_template_preset_id' => $preset->getKey(),
                    ],
                );
            }

            if (! in_array($preset->channel, MessageChannel::values(), true)) {
                yield $this->error(
                    code: 'messaging.customized_preset_channel_invalid',
                    message: "Customized MessageTemplatePreset [{$preset->key}] has unsupported channel [{$preset->channel}].",
                    source: 'message_template_presets',
                    path: "{$path}.channel",
                    context: [
                        'message_template_preset_id' => $preset->getKey(),
                        'message_template_preset_key' => $preset->key,
                    ],
                );

                continue;
            }

            if (! in_array($preset->purpose, MessagePurpose::values(), true)) {
                yield $this->error(
                    code: 'messaging.customized_preset_purpose_invalid',
                    message: "Customized MessageTemplatePreset [{$preset->key}] has unsupported purpose [{$preset->purpose}].",
                    source: 'message_template_presets',
                    path: "{$path}.purpose",
                    context: [
                        'message_template_preset_id' => $preset->getKey(),
                        'message_template_preset_key' => $preset->key,
                    ],
                );

                continue;
            }

            if (! $this->filledString($preset->scope)) {
                yield $this->error(
                    code: 'messaging.customized_preset_scope_missing',
                    message: "Customized MessageTemplatePreset [{$preset->key}] has no scope.",
                    source: 'message_template_presets',
                    path: "{$path}.scope",
                    context: [
                        'message_template_preset_id' => $preset->getKey(),
                        'message_template_preset_key' => $preset->key,
                    ],
                );

                continue;
            }

            $issues = $this->messageConfigValidator->validateDefinitionArray(
                definition: $preset->toMessageDefinition(),
                path: $path,
                channel: $preset->channel,
                purpose: $preset->purpose,
                scope: $preset->scope,
                surface: $preset->catalogEntries->first()?->surface,
            );

            foreach ($issues as $issue) {
                yield $this->findingFromIssue(
                    issue: $issue,
                    source: 'message_template_presets',
                    context: [
                        'message_template_preset_id' => $preset->getKey(),
                        'message_template_preset_key' => $preset->key,
                        'is_customized' => true,
                    ],
                    codePrefix: 'messaging.customized_preset',
                );
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateActiveAssignments(): iterable
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->with('messageTemplatePreset')
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $preset = $assignment->messageTemplatePreset;
            $path = "message_template_preset_assignments.{$assignment->getKey()}";
            $context = [
                'message_template_preset_assignment_id' => $assignment->getKey(),
                'message_template_preset_id' => $assignment->message_template_preset_id,
            ];

            if (! $preset instanceof MessageTemplatePreset) {
                yield $this->error(
                    code: 'messaging.assignment_preset_missing',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] references a missing preset.",
                    source: 'message_template_preset_assignments',
                    path: "{$path}.message_template_preset_id",
                    context: $context,
                );

                continue;
            }

            if (! $preset->isActive()) {
                yield $this->error(
                    code: 'messaging.assignment_preset_inactive',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] references inactive preset [{$preset->key}].",
                    source: 'message_template_preset_assignments',
                    path: "{$path}.message_template_preset_id",
                    context: $context + [
                        'message_template_preset_key' => $preset->key,
                    ],
                );
            }

            foreach (['channel', 'purpose', 'scope', 'message_type'] as $field) {
                if (! $this->filledString($assignment->{$field})) {
                    yield $this->error(
                        code: 'messaging.assignment_identity_missing',
                        message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] is missing [{$field}].",
                        source: 'message_template_preset_assignments',
                        path: "{$path}.{$field}",
                        context: $context + [
                            'field' => $field,
                        ],
                    );
                }
            }

            if (! in_array($assignment->channel, MessageChannel::values(), true)) {
                yield $this->error(
                    code: 'messaging.assignment_channel_invalid',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] has unsupported channel [{$assignment->channel}].",
                    source: 'message_template_preset_assignments',
                    path: "{$path}.channel",
                    context: $context,
                );
            }

            if (! in_array($assignment->purpose, MessagePurpose::values(), true)) {
                yield $this->error(
                    code: 'messaging.assignment_purpose_invalid',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] has unsupported purpose [{$assignment->purpose}].",
                    source: 'message_template_preset_assignments',
                    path: "{$path}.purpose",
                    context: $context,
                );
            }

            $hasContextType = $this->filledString($assignment->context_type);
            $hasContextId = $assignment->context_id !== null;

            if ($hasContextType !== $hasContextId) {
                yield $this->error(
                    code: 'messaging.assignment_context_incomplete',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] must define both context_type and context_id, or neither.",
                    source: 'message_template_preset_assignments',
                    path: $hasContextType
                        ? "{$path}.context_id"
                        : "{$path}.context_type",
                    context: $context,
                );
            }

            $hasCampaignKey = $this->filledString($assignment->campaign_key);
            $hasCampaignStep = $assignment->campaign_step !== null;

            if ($hasCampaignKey !== $hasCampaignStep) {
                yield $this->error(
                    code: 'messaging.assignment_campaign_context_incomplete',
                    message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] must define campaign_key and campaign_step together.",
                    source: 'message_template_preset_assignments',
                    path: $hasCampaignKey
                        ? "{$path}.campaign_step"
                        : "{$path}.campaign_key",
                    context: $context,
                );
            }
        }
    }


    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateAmbiguousStandardAssignments(): iterable
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->whereNull('campaign_key')
            ->whereNull('campaign_step')
            ->with('messageTemplatePreset')
            ->orderBy('id')
            ->get()
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

        foreach ($assignments as $assignment) {
            $configuredKeys = $this->configuredDefinitionKeysForMessageType(
                channel: (string) $assignment->channel,
                purpose: (string) $assignment->purpose,
                scope: (string) $assignment->scope,
                messageType: (string) $assignment->message_type,
            );

            if ($this->resolvedAssignmentDefinitionKey($assignment, $configuredKeys) !== null) {
                continue;
            }

            if (count($configuredKeys) < 2) {
                continue;
            }

            yield $this->error(
                code: 'messaging.assignment_definition_key_ambiguous',
                message: "Active MessageTemplatePresetAssignment [{$assignment->getKey()}] targets message type [{$assignment->message_type}], which has multiple definitions, but the assignment has no exact definition_key.",
                source: 'message_template_preset_assignments',
                path: "message_template_preset_assignments.{$assignment->getKey()}.definition_key",
                context: [
                    'message_template_preset_assignment_id' => $assignment->getKey(),
                    'message_template_preset_id' => $assignment->message_template_preset_id,
                    'channel' => $assignment->channel,
                    'purpose' => $assignment->purpose,
                    'scope' => $assignment->scope,
                    'message_type' => $assignment->message_type,
                    'available_definition_keys' => $configuredKeys,
                ],
            );
        }
    }

    /**
     * @param array<int, string> $configuredKeys
     */
    private function resolvedAssignmentDefinitionKey(
        MessageTemplatePresetAssignment $assignment,
        array $configuredKeys,
    ): ?string {
        $definitionKey = $this->assignmentDefinitionKey($assignment);

        if ($definitionKey !== null) {
            return $definitionKey;
        }

        if (count($configuredKeys) === 1) {
            return $configuredKeys[0];
        }

        return null;
    }

    private function assignmentDefinitionKey(MessageTemplatePresetAssignment $assignment): ?string
    {
        $definitionKey = $this->normalizedNullableString($assignment->definition_key)
            ?: $this->normalizedNullableString(data_get($assignment->meta, 'definition_key'));

        if ($definitionKey !== '') {
            return $definitionKey;
        }

        $sourceConfigPath = $this->nullableString($assignment->source_config_path);

        if ($sourceConfigPath !== null) {
            $definition = config($sourceConfigPath);
            $configuredKey = is_array($definition)
                ? $this->normalizedNullableString($definition['key'] ?? null)
                : '';

            if ($configuredKey !== '') {
                return $configuredKey;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function configuredDefinitionKeysForMessageType(
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
    ): array {
        $definitions = config(MessageDefinitionConfigPath::scope(
            $this->normalizedNullableString($channel),
            $this->normalizedNullableString($purpose),
            $this->normalizedNullableString($scope),
        ));

        if (! is_array($definitions)) {
            return [];
        }

        $messageType = $this->normalizedNullableString($messageType);
        $keys = [];

        foreach ($definitions as $sourceMessageType => $definition) {
            if ($sourceMessageType === 'campaigns' || ! is_string($sourceMessageType) || ! is_array($definition)) {
                continue;
            }

            $runtimeMessageType = Str::singular($this->normalizedNullableString($sourceMessageType));

            if ($runtimeMessageType !== $messageType) {
                continue;
            }

            $isList = array_is_list($definition);
            $definitionList = $isList ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition) || ! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $key = $this->normalizedNullableString($nestedDefinition['key'] ?? null);

                if ($key === '') {
                    $key = $isList
                        ? $runtimeMessageType.'_'.((int) $index + 1)
                        : $runtimeMessageType;
                }

                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateExactAssignmentAmbiguity(): iterable
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->with('messageTemplatePreset')
            ->orderBy('id')
            ->get()
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

        $groups = $assignments->groupBy(
            fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentIdentityKey($assignment),
        );

        foreach ($groups as $identityKey => $group) {
            if ($group->count() < 2) {
                continue;
            }

            /** @var MessageTemplatePresetAssignment $first */
            $first = $group->first();
            $assignmentIds = $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            yield $this->error(
                code: 'messaging.assignment_exact_ambiguity',
                message: 'Multiple active MessageTemplatePresetAssignments resolve to the same exact runtime identity.',
                source: 'message_template_preset_assignments',
                path: 'message_template_preset_assignments',
                context: [
                    'assignment_ids' => $assignmentIds,
                    'identity_key' => $identityKey,
                    'channel' => $first->channel,
                    'purpose' => $first->purpose,
                    'scope' => $first->scope,
                    'message_type' => $first->message_type,
                    'definition_key' => $this->assignmentDefinitionKey($first),
                    'campaign_key' => $first->campaign_key,
                    'campaign_step' => $first->campaign_step,
                    'campaign_step_variant_key' => $first->campaign_step_variant_key,
                    'source_config_path' => $this->assignmentSourceConfigPath($first),
                    'context_type' => $first->context_type,
                    'context_id' => $first->context_id,
                ],
            );
        }
    }

    private function assignmentIdentityKey(MessageTemplatePresetAssignment $assignment): string
    {
        $definitionKey = $this->assignmentDefinitionKey($assignment);
        $sourceConfigPath = $definitionKey === null
            ? $this->assignmentSourceConfigPath($assignment)
            : null;

        return implode('|', [
            $this->normalizedNullableString($assignment->channel),
            $this->normalizedNullableString($assignment->purpose),
            $this->normalizedNullableString($assignment->scope),
            $this->normalizedNullableString($assignment->message_type),
            $definitionKey ?? '',
            $sourceConfigPath ?? '',
            $this->normalizedNullableString($assignment->campaign_key),
            (string) ($assignment->campaign_step ?? ''),
            $this->normalizedNullableString($assignment->campaign_step_variant_key),
            $this->nullableString($assignment->context_type) ?? '',
            (string) ($assignment->context_id ?? ''),
        ]);
    }

    private function assignmentSourceConfigPath(MessageTemplatePresetAssignment $assignment): ?string
    {
        return $this->nullableString($assignment->source_config_path)
            ?? $this->nullableString(data_get($assignment->meta, 'source_config_path'))
            ?? $this->nullableString($assignment->messageTemplatePreset?->source_config_path);
    }

    /**
     * @param array{level: string, path: string, message: string} $issue
     * @param array<string, mixed> $context
     */
    private function findingFromIssue(
        array $issue,
        string $source,
        array $context,
        string $codePrefix = 'messaging.config',
    ): SetupValidationFinding {
        $severity = ($issue['level'] ?? null) === SetupValidationFinding::SEVERITY_ERROR
            ? SetupValidationFinding::SEVERITY_ERROR
            : SetupValidationFinding::SEVERITY_WARNING;

        return new SetupValidationFinding(
            severity: $severity,
            code: $codePrefix.'.'.$this->codeSegment($issue['message'] ?? 'validation_issue'),
            message: (string) ($issue['message'] ?? 'Messaging validation issue.'),
            source: $source,
            path: isset($issue['path']) && is_string($issue['path'])
                ? $issue['path']
                : null,
            module: self::MODULE,
            context: $context,
        );
    }

    private function codeSegment(string $message): string
    {
        $value = strtolower(trim($message));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? 'validation_issue';

        return trim($value, '_') ?: 'validation_issue';
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizedNullableString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $source,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: $source,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
