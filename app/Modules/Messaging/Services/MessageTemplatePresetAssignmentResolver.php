<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;

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
        $assignments = $this->activeBaseQuery($channel, $purpose, $scope)
            ->whereNull('campaign_key')
            ->whereNull('campaign_step')
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->with('messageTemplatePreset')
            ->orderBy('message_type')
            ->orderBy('id')
            ->get();

        return $assignments
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive())
            ->map(fn (MessageTemplatePresetAssignment $assignment): array => $assignment->messageTemplatePreset->toMessageDefinition($assignment))
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
    ): ?array {
        $assignment = $this->activeBaseQuery($channel, $purpose, $scope)
            ->where('campaign_key', $this->normalizeSegment($campaignKey))
            ->where('campaign_step', $stepNumber)
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->with('messageTemplatePreset')
            ->orderBy('id')
            ->get()
            ->first(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

        return $assignment instanceof MessageTemplatePresetAssignment
            ? $assignment->messageTemplatePreset->toMessageDefinition($assignment)
            : null;
    }

    private function activeBaseQuery(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
    ): \Illuminate\Database\Eloquent\Builder {
        return MessageTemplatePresetAssignment::query()
            ->active()
            ->where('channel', $this->normalizeChannel($channel))
            ->where('purpose', $this->normalizeSegment($purpose))
            ->where('scope', $this->normalizeSegment($scope));
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : $this->normalizeSegment($channel);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
