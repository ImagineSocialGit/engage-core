<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Database\Eloquent\Builder;
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
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->with('messageTemplatePreset')
            ->orderBy('message_type')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive())
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => (string) $assignment->message_type)
            ->values();

        return $assignments
            ->map(fn (MessageTemplatePresetAssignment $assignment): array => $assignment->messageTemplatePreset->toMessageDefinition($assignment))
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
            ->orderByDesc('id')
            ->get()
            ->first(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

        return $assignment instanceof MessageTemplatePresetAssignment
            ? $assignment->messageTemplatePreset->toMessageDefinition($assignment)
            : null;
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
