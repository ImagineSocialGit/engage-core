<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Services\MessageChainVersionCarouselPresenter;
use App\Modules\Messaging\Services\MessageTemplateCatalogCarouselPresenter;
use Illuminate\Support\Collection;

final class CampaignMessageReviewPresenter
{
    public function __construct(
        private readonly MessageTemplateCatalogCarouselPresenter $carouselPresenter,
        private readonly MessageChainVersionCarouselPresenter $chainCarouselPresenter,
    ) {}

    /**
     * @return array{
     *     presentation: array<string, mixed>,
     *     message_count: int,
     *     initial_message_id: string|null,
     *     message_chain_version_id: int|null,
     *     version: int|null
     * }
     */
    public function forCampaign(
        Campaign $campaign,
        ?string $initialMessageId = null,
    ): array {
        $currentVersion = $this->currentVersion($campaign);

        if ($currentVersion instanceof MessageChainVersion) {
            $presentation = $this->campaignPresentation(
                campaign: $campaign,
                version: $currentVersion,
            );

            return [
                'presentation' => $presentation,
                'message_count' => (int) ($presentation['message_count'] ?? 0),
                'initial_message_id' => $this->availableInitialMessageId(
                    presentation: $presentation,
                    requested: $initialMessageId,
                ),
                'message_chain_version_id' => (int) $currentVersion->getKey(),
                'version' => (int) $currentVersion->version,
            ];
        }

        $campaign->loadMissing([
            'steps' => fn ($query) => $query
                ->active()
                ->with([
                    'variants' => fn ($variantQuery) => $variantQuery
                        ->active()
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->orderBy('step_number'),
        ]);

        $assignments = $this->currentAssignments($campaign);
        $entries = $this->selectedEntries($campaign, $assignments);
        $presentation = $this->carouselPresenter->present($entries);

        return [
            'presentation' => $presentation,
            'message_count' => (int) ($presentation['message_count'] ?? 0),
            'initial_message_id' => $this->availableInitialMessageId(
                presentation: $presentation,
                requested: $initialMessageId,
            ),
            'message_chain_version_id' => null,
            'version' => null,
        ];
    }

    private function currentVersion(Campaign $campaign): ?MessageChainVersion
    {
        $campaign->loadMissing('messageChain.currentVersion.steps.variants');
        $chain = $campaign->messageChain;

        if (! $chain instanceof MessageChain) {
            return null;
        }

        $version = $chain->currentVersion;

        return $version instanceof MessageChainVersion && $version->isPublished()
            ? $version
            : null;
    }

    /** @return array<string, mixed> */
    private function campaignPresentation(
        Campaign $campaign,
        MessageChainVersion $version,
    ): array {
        $presentation = $this->chainCarouselPresenter->present($version);
        $channels = is_array($presentation['channels'] ?? null)
            ? $presentation['channels']
            : [];

        foreach ($channels as $channelKey => $channel) {
            $messages = is_array($channel['messages'] ?? null)
                ? $channel['messages']
                : [];

            foreach ($messages as $messageIndex => $message) {
                $variantId = (int) ($message['message_chain_step_variant_id'] ?? 0);
                $presetId = (int) ($message['preset_id'] ?? 0);

                $message['area_label'] = 'Campaign schedule';
                $message['update_action'] = $variantId > 0 && $presetId > 0
                    ? route('crm.campaigns.messages.update', [
                        'campaign' => $campaign,
                        'messageChainStepVariant' => $variantId,
                        'messageTemplatePreset' => $presetId,
                    ])
                    : '';
                $message['reply_handling_update_action'] = $variantId > 0
                    ? route('crm.campaigns.messages.reply-handling.update', [
                        'campaign' => $campaign,
                        'messageChainStepVariant' => $variantId,
                    ])
                    : '';
                $messages[$messageIndex] = $message;
            }

            $channel['messages'] = $messages;
            $channels[$channelKey] = $channel;
        }

        $presentation['channels'] = $channels;

        return $presentation;
    }

    /** @return Collection<string, MessageTemplatePresetAssignment> */
    private function currentAssignments(Campaign $campaign): Collection
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->with(['messageTemplatePreset.catalogEntries'])
            ->where('surface', 'campaigns')
            ->where('campaign_key', $campaign->key)
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->orderByDesc('id')
            ->get();

        return $assignments
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => implode(':', [
                $assignment->channel,
                $assignment->purpose,
                $assignment->scope,
                $assignment->campaign_step,
                $assignment->campaign_step_variant_key ?? '',
            ]))
            ->keyBy(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentKey(
                channel: $assignment->channel,
                purpose: $assignment->purpose,
                scope: $assignment->scope,
                stepNumber: (int) $assignment->campaign_step,
                variantKey: $assignment->campaign_step_variant_key,
            ));
    }

    /**
     * @param Collection<string, MessageTemplatePresetAssignment> $assignments
     * @return Collection<int, MessageTemplateCatalogEntry>
     */
    private function selectedEntries(
        Campaign $campaign,
        Collection $assignments,
    ): Collection {
        $entries = collect();

        foreach ($campaign->steps as $step) {
            if (! $step instanceof CampaignStep) {
                continue;
            }

            foreach ($step->variants as $variant) {
                if (! $variant instanceof CampaignStepVariant) {
                    continue;
                }

                $assignment = $assignments->get($this->variantKey($step, $variant));
                $preset = $assignment?->messageTemplatePreset;

                if (! $preset) {
                    continue;
                }

                $entry = $preset->catalogEntries->first(
                    fn (mixed $candidate): bool => $candidate instanceof MessageTemplateCatalogEntry
                        && $candidate->module_key === 'campaigns'
                        && $candidate->usage_type === 'campaign_step'
                        && data_get($candidate->meta, 'campaign_key') === $campaign->key
                        && (int) data_get($candidate->meta, 'campaign_step') === (int) $step->step_number
                        && data_get($candidate->meta, 'campaign_step_variant_key') === $variant->key,
                );

                if (! $entry instanceof MessageTemplateCatalogEntry) {
                    $entry = $preset->catalogEntries->first(
                        fn (mixed $candidate): bool => $candidate instanceof MessageTemplateCatalogEntry
                            && $candidate->module_key === 'campaigns',
                    );
                }

                if ($entry instanceof MessageTemplateCatalogEntry) {
                    $entry->setRelation('messageTemplatePreset', $preset);
                    $entries->push($entry);
                }
            }
        }

        return $entries
            ->unique(fn (MessageTemplateCatalogEntry $entry): int => (int) $entry->message_template_preset_id)
            ->values();
    }

    /** @param array<string, mixed> $presentation */
    private function availableInitialMessageId(
        array $presentation,
        ?string $requested,
    ): ?string {
        if ($requested === null || trim($requested) === '') {
            return null;
        }

        foreach ($presentation['channels'] ?? [] as $channel) {
            foreach ($channel['messages'] ?? [] as $message) {
                if ((string) ($message['id'] ?? '') === $requested) {
                    return $requested;
                }
            }
        }

        return null;
    }

    private function variantKey(
        CampaignStep $step,
        CampaignStepVariant $variant,
    ): string {
        return $this->assignmentKey(
            channel: $variant->channel,
            purpose: $variant->purpose,
            scope: $variant->scope,
            stepNumber: (int) $step->step_number,
            variantKey: $variant->key,
        );
    }

    private function assignmentKey(
        string $channel,
        string $purpose,
        string $scope,
        int $stepNumber,
        ?string $variantKey,
    ): string {
        return implode(':', [
            $this->normalizeSegment($channel),
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
            $stepNumber,
            $variantKey !== null ? $this->normalizeSegment($variantKey) : '',
        ]);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}