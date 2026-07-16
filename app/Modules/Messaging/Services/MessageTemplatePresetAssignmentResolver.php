<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

        $broadAssignments = $assignments
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => $this->nullableString($assignment->source_config_path) === null)
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => $this->normalizeSegment((string) $assignment->message_type))
            ->values();

        $broadMessageTypes = $broadAssignments
            ->map(fn (MessageTemplatePresetAssignment $assignment): string => $this->normalizeSegment((string) $assignment->message_type))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sourceSpecificAssignments = $assignments
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => $this->nullableString($assignment->source_config_path) !== null)
            ->reject(fn (MessageTemplatePresetAssignment $assignment): bool => in_array(
                $this->normalizeSegment((string) $assignment->message_type),
                $broadMessageTypes,
                true,
            ))
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentDefinitionKey($assignment))
            ->values();

        $selectedAssignmentIds = $broadAssignments
            ->merge($sourceSpecificAssignments)
            ->map(fn (MessageTemplatePresetAssignment $assignment): mixed => $assignment->getKey())
            ->all();

        return $assignments
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => in_array(
                $assignment->getKey(),
                $selectedAssignmentIds,
                true,
            ))
            ->map(fn (MessageTemplatePresetAssignment $assignment): array => $this->definitionForAssignment($assignment))
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
    private function definitionForAssignment(MessageTemplatePresetAssignment $assignment): array
    {
        $definition = $assignment->messageTemplatePreset->toMessageDefinition($assignment);

        $sourceConfigPath = $this->nullableString($assignment->source_config_path)
            ?? $this->nullableString(data_get($assignment->meta, 'source_config_path'))
            ?? $this->nullableString($assignment->messageTemplatePreset?->source_config_path);

        $variantKey = $this->normalizeNullableSegment($assignment->campaign_step_variant_key)
            ?? $this->normalizeNullableSegment(data_get($assignment->meta, 'campaign_step_variant_key'));

        return array_replace_recursive($definition, [
            'key' => $this->standardDefinitionKey($assignment, $sourceConfigPath),
            'campaign_step_variant_key' => $variantKey,
            'source_config_path' => $sourceConfigPath,
            'meta' => [
                'message_template_assignment' => array_filter([
                    'id' => $assignment->getKey(),
                    'campaign_step_variant_key' => $variantKey,
                    'source_config_path' => $sourceConfigPath,
                ], fn (mixed $value): bool => $value !== null),
            ],
        ]);
    }

    private function standardDefinitionKey(
        MessageTemplatePresetAssignment $assignment,
        ?string $sourceConfigPath,
    ): string {
        if ($sourceConfigPath !== null) {
            $configuredDefinition = config($sourceConfigPath);
            $configuredKey = is_array($configuredDefinition)
                ? $this->normalizeNullableSegment($configuredDefinition['key'] ?? null)
                : null;

            if ($configuredKey !== null) {
                return $configuredKey;
            }
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

        return $this->normalizeSegment((string) $assignment->message_type);
    }

    private function assignmentDefinitionKey(MessageTemplatePresetAssignment $assignment): string
    {
        $sourceConfigPath = $assignment->source_config_path
            ?? data_get($assignment->meta, 'source_config_path')
            ?? $assignment->messageTemplatePreset?->source_config_path;

        return implode('|', [
            (string) $assignment->message_type,
            is_string($sourceConfigPath) ? trim($sourceConfigPath) : '',
            (string) ($assignment->campaign_key ?? ''),
            (string) ($assignment->campaign_step ?? ''),
            (string) ($assignment->campaign_step_variant_key ?? ''),
        ]);
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




