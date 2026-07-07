<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class MessageTemplateUsageResolver
{
    /**
     * @return Collection<int, array{module_label: string, context_label: string, item_label: string, detail: string|null, url: string|null}>
     */
    public function forPreset(MessageTemplatePreset $preset): Collection
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = $preset->assignments()
            ->active()
            ->orderBy('surface')
            ->orderBy('campaign_key')
            ->orderBy('campaign_step')
            ->orderBy('message_type')
            ->get();

        return $assignments
            ->map(fn (MessageTemplatePresetAssignment $assignment): array => $this->usageRow($preset, $assignment))
            ->values();
    }

    /**
     * @return array{module_label: string, context_label: string, item_label: string, detail: string|null, url: string|null}
     */
    private function usageRow(MessageTemplatePreset $preset, MessageTemplatePresetAssignment $assignment): array
    {
        $catalogEntry = $this->catalogEntryForAssignment($preset, $assignment);

        $moduleLabel = $catalogEntry?->module_label ?? $this->moduleLabel($assignment, $preset);
        $groupLabel = $catalogEntry?->group_label
            ?? data_get($assignment->meta, 'catalog.group_label')
            ?? $this->fallbackGroupLabel($assignment, $preset);
        $itemLabel = $catalogEntry?->item_label
            ?? data_get($assignment->meta, 'catalog.item_label')
            ?? $this->fallbackItemLabel($assignment, $preset);

        return [
            'module_label' => $moduleLabel,
            'context_label' => $groupLabel,
            'item_label' => $itemLabel,
            'detail' => $this->detail($assignment),
            'url' => $this->url($assignment),
        ];
    }

    private function catalogEntryForAssignment(
        MessageTemplatePreset $preset,
        MessageTemplatePresetAssignment $assignment,
    ): ?MessageTemplateCatalogEntry {
        $sourceConfigPath = data_get($assignment->meta, 'source_config_path');

        $query = $preset->catalogEntries()
            ->active()
            ->where('channel', $assignment->channel)
            ->where('purpose', $assignment->purpose)
            ->where('scope', $assignment->scope);

        if (is_string($sourceConfigPath) && trim($sourceConfigPath) !== '') {
            $match = (clone $query)->where('source_config_path', $sourceConfigPath)->first();

            if ($match instanceof MessageTemplateCatalogEntry) {
                return $match;
            }
        }

        $itemKey = data_get($assignment->meta, 'catalog.item_key');

        if (is_string($itemKey) && trim($itemKey) !== '') {
            $match = (clone $query)->where('item_key', $itemKey)->first();

            if ($match instanceof MessageTemplateCatalogEntry) {
                return $match;
            }
        }

        if ($assignment->campaign_key !== null && $assignment->campaign_step !== null) {
            $match = (clone $query)
                ->where('usage_type', 'campaign_step')
                ->where('meta->campaign_key', $assignment->campaign_key)
                ->where('meta->campaign_step', $assignment->campaign_step)
                ->first();

            if ($match instanceof MessageTemplateCatalogEntry) {
                return $match;
            }
        }

        return $query->orderBy('item_order')->first();
    }

    private function moduleLabel(
        MessageTemplatePresetAssignment $assignment,
        MessageTemplatePreset $preset,
    ): string {
        return match ($assignment->surface) {
            'campaigns' => 'Campaigns',
            'webinar_registrations', 'webinar_waitlists' => 'Webinars',
            default => str_starts_with($preset->scope, 'webinar') ? 'Webinars' : 'Messaging',
        };
    }

    private function fallbackGroupLabel(
        MessageTemplatePresetAssignment $assignment,
        MessageTemplatePreset $preset,
    ): string {
        if ($assignment->campaign_key !== null) {
            return Str::headline(str_replace('_', ' ', $assignment->campaign_key));
        }

        return Str::headline(str_replace('_', ' ', $preset->scope));
    }

    private function fallbackItemLabel(
        MessageTemplatePresetAssignment $assignment,
        MessageTemplatePreset $preset,
    ): string {
        if ($assignment->campaign_step !== null) {
            return 'Step '.$assignment->campaign_step.' '.($assignment->channel === 'sms' ? 'SMS' : Str::headline($assignment->channel));
        }

        return Str::headline(str_replace('_', ' ', $assignment->message_type ?? $preset->message_type ?? 'Message'));
    }

    private function url(MessageTemplatePresetAssignment $assignment): ?string
    {
        if (
            $assignment->surface === 'campaigns'
            && $assignment->campaign_key !== null
            && $assignment->campaign_step !== null
            && Route::has('crm.campaigns.message-templates.index')
        ) {
            return route('crm.campaigns.message-templates.index', [
                'campaign' => $assignment->campaign_key,
                'step' => $assignment->campaign_step,
            ]);
        }

        return null;
    }

    private function detail(MessageTemplatePresetAssignment $assignment): ?string
    {
        $parts = array_filter([
            $assignment->surface ? Str::headline(str_replace('_', ' ', $assignment->surface)) : null,
            $assignment->message_type ? Str::headline(str_replace('_', ' ', $assignment->message_type)) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}

