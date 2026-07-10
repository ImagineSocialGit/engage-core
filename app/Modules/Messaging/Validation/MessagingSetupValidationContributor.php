<?php

namespace App\Modules\Messaging\Validation;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Services\MessageConfigValidator;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;

class MessagingSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'messaging';

    public function __construct(
        private readonly MessageConfigValidator $messageConfigValidator,
    ) {}

    public function findings(): iterable
    {
        $allowedTokens = $this->documentedTokens();

        yield from $this->validateConfigRoutes($allowedTokens);
        yield from $this->validateCustomizedPresets($allowedTokens);
        yield from $this->validateActiveAssignments();
        yield from $this->validateExactAssignmentAmbiguity();
    }

    /**
     * @param array<int, string> $allowedTokens
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateConfigRoutes(array $allowedTokens): iterable
    {
        foreach (['email', 'sms'] as $channel) {
            $channelConfig = config("messaging.{$channel}", []);

            if (! is_array($channelConfig)) {
                yield $this->error(
                    code: 'messaging.channel_config_invalid',
                    message: "Messaging channel config [{$channel}] must be an array.",
                    source: "messaging.{$channel}",
                    path: "messaging.{$channel}",
                    context: [
                        'channel' => $channel,
                    ],
                );

                continue;
            }

            foreach (['transactional', 'marketing', 'internal'] as $purpose) {
                $purposeConfig = $channelConfig[$purpose] ?? null;

                if ($purposeConfig === null) {
                    continue;
                }

                if (! is_array($purposeConfig)) {
                    yield $this->error(
                        code: 'messaging.purpose_config_invalid',
                        message: "Messaging purpose config [{$channel}.{$purpose}] must be an array.",
                        source: "messaging.{$channel}.{$purpose}",
                        path: "messaging.{$channel}.{$purpose}",
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
                            source: "messaging.{$channel}.{$purpose}",
                            path: "messaging.{$channel}.{$purpose}",
                            context: [
                                'channel' => $channel,
                                'purpose' => $purpose,
                            ],
                        );

                        continue;
                    }

                    if (! is_array($scopeConfig)) {
                        yield $this->error(
                            code: 'messaging.scope_config_invalid',
                            message: "Messaging scope config [{$channel}.{$purpose}.{$scope}] must be an array.",
                            source: "messaging.{$channel}.{$purpose}.{$scope}",
                            path: "messaging.{$channel}.{$purpose}.{$scope}",
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
                        allowedTokens: $allowedTokens,
                    );

                    foreach ($issues as $issue) {
                        yield $this->findingFromIssue(
                            issue: $issue,
                            source: "messaging.{$channel}.{$purpose}.{$scope}",
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
     * @param array<int, string> $allowedTokens
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateCustomizedPresets(array $allowedTokens): iterable
    {
        /** @var Collection<int, MessageTemplatePreset> $presets */
        $presets = MessageTemplatePreset::query()
            ->where('is_customized', true)
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
                allowedTokens: $allowedTokens,
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
        return implode('|', [
            $this->normalizedNullableString($assignment->channel),
            $this->normalizedNullableString($assignment->purpose),
            $this->normalizedNullableString($assignment->scope),
            $this->normalizedNullableString($assignment->message_type),
            $this->assignmentSourceConfigPath($assignment) ?? '',
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
     * @return array<int, string>
     */
    private function documentedTokens(): array
    {
        $reference = config('reference.tokens', []);

        if (! is_array($reference)) {
            return [];
        }

        $tokens = [];
        $listTokenKeys = [
            'approved_aliases',
            'caller_supplied_aliases',
            'flow_route_only_tokens',
        ];

        $collect = function (mixed $value, ?string $parentKey = null) use (&$collect, &$tokens, $listTokenKeys): void {
            if (is_string($value)) {
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}$/', trim($value), $matches) === 1) {
                    $tokens[] = $matches[1];

                    return;
                }

                if (in_array($parentKey, $listTokenKeys, true) && trim($value) !== '') {
                    $tokens[] = trim($value);
                }

                return;
            }

            if (! is_array($value)) {
                return;
            }

            if ($parentKey === 'aliases' && ! array_is_list($value)) {
                foreach (array_keys($value) as $alias) {
                    if (is_string($alias) && trim($alias) !== '') {
                        $tokens[] = trim($alias);
                    }
                }
            }

            foreach ($value as $key => $nestedValue) {
                $collect(
                    value: $nestedValue,
                    parentKey: is_string($key) ? $key : $parentKey,
                );
            }
        };

        $collect($reference);

        return array_values(array_unique($tokens));
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
