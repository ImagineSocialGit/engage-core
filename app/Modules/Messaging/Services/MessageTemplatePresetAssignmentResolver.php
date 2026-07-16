<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageTemplatePresetAssignmentResolver
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveDefinitions(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
    ): array {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizeSegment($purpose);
        $scope = $this->normalizeSegment($scope);
        $configuredKeys = $this->configuredDefinitionKeysByMessageType($channel, $purpose, $scope);

        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = $this->activeBaseQuery($channel, $purpose, $scope)
            ->whereNull('campaign_key')
            ->whereNull('campaign_step')
            ->whereNull('campaign_step_variant_key')
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->with('messageTemplatePreset')
            ->orderBy('message_type')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive())
            ->values();

        return $assignments
            ->map(function (MessageTemplatePresetAssignment $assignment) use ($configuredKeys): ?array {
                $messageType = $this->normalizeSegment((string) $assignment->message_type);
                $definitionKey = $this->resolvedAssignmentDefinitionKey(
                    assignment: $assignment,
                    configuredKeys: $configuredKeys[$messageType] ?? [],
                );

                if ($definitionKey === null) {
                    return null;
                }

                return [
                    'identity' => $messageType.'|'.$definitionKey,
                    'definition' => $this->definitionForAssignment($assignment, $definitionKey),
                ];
            })
            ->filter()
            ->unique('identity')
            ->pluck('definition')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveCampaignStep(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
        string $campaignKey,
        int $stepNumber,
        ?string $variantKey = null,
        ?string $sourceConfigPath = null,
        ?Model $context = null,
    ): ?array {
        $variantKey = $this->normalizeNullableSegment($variantKey);
        $sourceConfigPath = $this->nullableString($sourceConfigPath);

        if ($context instanceof Model && $variantKey !== null) {
            $assignment = $this->campaignStepQuery($channel, $purpose, $scope, $campaignKey, $stepNumber)
                ->where('campaign_step_variant_key', $variantKey)
                ->where('context_type', $context->getMorphClass())
                ->where('context_id', $context->getKey())
                ->with('messageTemplatePreset')
                ->orderByDesc('id')
                ->get()
                ->first(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

            if ($assignment instanceof MessageTemplatePresetAssignment) {
                return $this->definitionForAssignment($assignment);
            }
        }

        if ($variantKey !== null) {
            $assignment = $this->campaignStepQuery($channel, $purpose, $scope, $campaignKey, $stepNumber)
                ->where('campaign_step_variant_key', $variantKey)
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->with('messageTemplatePreset')
                ->orderByDesc('id')
                ->get()
                ->first(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

            if ($assignment instanceof MessageTemplatePresetAssignment) {
                return $this->definitionForAssignment($assignment);
            }
        }

        if ($sourceConfigPath !== null) {
            $assignment = $this->campaignStepQuery($channel, $purpose, $scope, $campaignKey, $stepNumber)
                ->where('source_config_path', $sourceConfigPath)
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->with('messageTemplatePreset')
                ->orderByDesc('id')
                ->get()
                ->first(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

            if ($assignment instanceof MessageTemplatePresetAssignment) {
                return $this->definitionForAssignment($assignment);
            }
        }

        return null;
    }

    /**
     * @return Builder<MessageTemplatePresetAssignment>
     */
    private function campaignStepQuery(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
        string $campaignKey,
        int $stepNumber,
    ): Builder {
        return $this->activeBaseQuery($channel, $purpose, $scope)
            ->where('campaign_key', $this->normalizeSegment($campaignKey))
            ->where('campaign_step', $stepNumber);
    }

    /**
     * @return Builder<MessageTemplatePresetAssignment>
     */
    private function activeBaseQuery(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
    ): Builder {
        return MessageTemplatePresetAssignment::query()
            ->active()
            ->where('channel', $this->normalizeChannel($channel))
            ->where('purpose', $this->normalizeSegment($purpose))
            ->where('scope', $this->normalizeSegment($scope));
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionForAssignment(
        MessageTemplatePresetAssignment $assignment,
        ?string $definitionKey = null,
    ): array {
        $definition = $assignment->messageTemplatePreset->toMessageDefinition($assignment);

        $sourceConfigPath = $this->nullableString($assignment->source_config_path)
            ?? $this->nullableString(data_get($assignment->meta, 'source_config_path'))
            ?? $this->nullableString($assignment->messageTemplatePreset?->source_config_path);

        $variantKey = $this->normalizeNullableSegment($assignment->campaign_step_variant_key)
            ?? $this->normalizeNullableSegment(data_get($assignment->meta, 'campaign_step_variant_key'));

        $definitionKey ??= $this->normalizeNullableSegment($assignment->definition_key)
            ?? $this->normalizeNullableSegment(data_get($assignment->meta, 'definition_key'));

        return array_replace_recursive($definition, array_filter([
            'key' => $definitionKey,
            'definition_key' => $definitionKey,
            'campaign_step_variant_key' => $variantKey,
            'source_config_path' => $sourceConfigPath,
            'meta' => [
                'message_template_assignment' => array_filter([
                    'id' => $assignment->getKey(),
                    'definition_key' => $definitionKey,
                    'campaign_step_variant_key' => $variantKey,
                    'source_config_path' => $sourceConfigPath,
                ], fn (mixed $value): bool => $value !== null),
            ],
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param array<int, string> $configuredKeys
     */
    private function resolvedAssignmentDefinitionKey(
        MessageTemplatePresetAssignment $assignment,
        array $configuredKeys,
    ): ?string {
        $definitionKey = $this->normalizeNullableSegment($assignment->definition_key)
            ?? $this->normalizeNullableSegment(data_get($assignment->meta, 'definition_key'));

        if ($definitionKey !== null) {
            return $definitionKey;
        }

        $assignmentSourceConfigPath = $this->nullableString($assignment->source_config_path);

        if ($assignmentSourceConfigPath !== null) {
            $configuredDefinition = config($assignmentSourceConfigPath);
            $configuredKey = is_array($configuredDefinition)
                ? $this->normalizeNullableSegment($configuredDefinition['key'] ?? null)
                : null;

            if ($configuredKey !== null) {
                return $configuredKey;
            }
        }

        $presetSeedKey = $this->normalizeNullableSegment(
            data_get($assignment->messageTemplatePreset?->meta, 'seed.definition_key'),
        );

        if ($presetSeedKey !== null) {
            return $presetSeedKey;
        }

        $configuredKeys = array_values(array_unique(array_filter($configuredKeys)));

        if (count($configuredKeys) === 1) {
            return $configuredKeys[0];
        }

        if ($configuredKeys !== []) {
            return null;
        }

        $presetKey = $this->nullableString($assignment->messageTemplatePreset?->key);

        if ($presetKey !== null) {
            $lastSeparatorPosition = strrpos($presetKey, '.');
            $semanticKey = $lastSeparatorPosition === false
                ? $presetKey
                : substr($presetKey, $lastSeparatorPosition + 1);

            $semanticKey = $this->normalizeNullableSegment($semanticKey);

            if ($semanticKey !== null) {
                return $semanticKey;
            }
        }

        return $this->normalizeNullableSegment($assignment->message_type);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function configuredDefinitionKeysByMessageType(
        string $channel,
        string $purpose,
        string $scope,
    ): array {
        $definitions = config(MessageDefinitionConfigPath::scope($channel, $purpose, $scope));

        if (! is_array($definitions)) {
            return [];
        }

        $keys = [];

        foreach ($definitions as $sourceMessageType => $definition) {
            if ($sourceMessageType === 'campaigns' || ! is_string($sourceMessageType) || ! is_array($definition)) {
                continue;
            }

            $messageType = Str::singular($this->normalizeSegment($sourceMessageType));
            $isList = array_is_list($definition);
            $definitionList = $isList ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition) || ! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $definitionKey = $this->normalizeNullableSegment($nestedDefinition['key'] ?? null)
                    ?? ($isList ? $messageType.'_'.((int) $index + 1) : $messageType);

                $keys[$messageType][] = $definitionKey;
            }
        }

        return array_map(
            fn (array $values): array => array_values(array_unique($values)),
            $keys,
        );
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : $this->normalizeSegment($channel);
    }

    private function normalizeNullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
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
}
