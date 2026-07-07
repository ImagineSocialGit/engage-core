<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Database\Eloquent\Model;

class AssignMessageTemplatePresetAction
{
    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        MessageTemplatePreset $preset,
        string $channel,
        string $purpose,
        string $scope,
        ?string $surface = null,
        ?string $messageType = null,
        ?string $campaignKey = null,
        ?int $campaignStep = null,
        ?Model $context = null,
        array $meta = [],
    ): MessageTemplatePresetAssignment {
        $attributes = [
            'channel' => $this->normalizeSegment($channel),
            'purpose' => $this->normalizeSegment($purpose),
            'scope' => $this->normalizeSegment($scope),
            'surface' => $surface !== null ? $this->normalizeSegment($surface) : null,
            'message_type' => $messageType !== null ? $this->normalizeSegment($messageType) : $preset->message_type,
            'campaign_key' => $campaignKey !== null ? $this->normalizeSegment($campaignKey) : null,
            'campaign_step' => $campaignStep,
            'context_type' => $context?->getMorphClass(),
            'context_id' => $context?->getKey(),
        ];

        $assignment = $this->matchingAssignment($attributes);

        if (! $assignment instanceof MessageTemplatePresetAssignment) {
            $assignment = new MessageTemplatePresetAssignment($attributes);
        }

        $assignment->forceFill([
            'message_template_preset_id' => $preset->getKey(),
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'meta' => array_replace_recursive($assignment->meta ?? [], $this->assignmentMeta($preset, $meta)),
        ])->save();

        return $assignment;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function matchingAssignment(array $attributes): ?MessageTemplatePresetAssignment
    {
        $query = MessageTemplatePresetAssignment::query()
            ->where('channel', $attributes['channel'])
            ->where('purpose', $attributes['purpose'])
            ->where('scope', $attributes['scope']);

        foreach (['surface', 'message_type', 'campaign_key', 'campaign_step', 'context_type', 'context_id'] as $column) {
            if (($attributes[$column] ?? null) === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $attributes[$column]);
            }
        }

        return $query->orderByDesc('is_active')->orderByDesc('id')->first();
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function assignmentMeta(MessageTemplatePreset $preset, array $meta): array
    {
        $catalogEntry = $preset->catalogEntries()
            ->active()
            ->orderBy('item_order')
            ->first();

        $catalogMeta = $catalogEntry instanceof MessageTemplateCatalogEntry
            ? [
                'group_key' => $catalogEntry->group_key,
                'group_label' => $catalogEntry->group_label,
                'item_key' => $catalogEntry->item_key,
                'item_label' => $catalogEntry->item_label,
            ]
            : [];

        return array_replace_recursive([
            'source' => 'crm_assignment',
            'source_config_path' => $preset->source_config_path,
            'assigned_at' => now()->toISOString(),
            'catalog' => $catalogMeta,
        ], $meta);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
