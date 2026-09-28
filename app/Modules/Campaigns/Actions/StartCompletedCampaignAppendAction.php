<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Jobs\ProcessCompletedCampaignAppendChunkJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignMessageChainAppend;
use App\Modules\Campaigns\Services\CampaignEligibilityEvaluator;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartCompletedCampaignAppendAction
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContact,
        private readonly CampaignEligibilityEvaluator $eligibility,
        private readonly MessageEligibilityGate $messageGate,
        private readonly RecordPriorCampaignMessageReceiptAction $priorReceipts,
    ) {}

    /** @return array{append_id: int, count: int}|null */
    public function prompt(Campaign $campaign): ?array
    {
        $append = $this->currentAppend($campaign);

        if (! $append instanceof CampaignMessageChainAppend) {
            return null;
        }

        $count = $this->candidates($campaign, $append)->count();

        return $count > 0
            ? ['append_id' => (int) $append->getKey(), 'count' => $count]
            : null;
    }

    public function start(Campaign $campaign, int $appendId): int
    {
        $append = $this->currentAppend($campaign);

        if (! $append instanceof CampaignMessageChainAppend
            || (int) $append->getKey() !== $appendId
        ) {
            throw ValidationException::withMessages([
                'append_id' => 'The Campaign schedule changed. Reload and review the current message before starting contacts.',
            ]);
        }

        $count = $this->candidates($campaign, $append)->count();

        if ($count > 0) {
            ProcessCompletedCampaignAppendChunkJob::dispatch($appendId, 0)->afterCommit();
        }

        return $count;
    }

    public function processChunk(int $appendId, int $afterId): void
    {
        $append = CampaignMessageChainAppend::query()->find($appendId);

        if (! $append instanceof CampaignMessageChainAppend) {
            return;
        }

        $campaign = Campaign::query()->find($append->campaign_id);

        if (! $campaign instanceof Campaign || ! $this->isCurrent($campaign, $append)) {
            return;
        }

        $ids = $this->candidates($campaign, $append)
            ->where('campaign_enrollments.id', '>', $afterId)
            ->orderBy('campaign_enrollments.id')
            ->limit(self::CHUNK_SIZE)
            ->pluck('campaign_enrollments.id');

        foreach ($ids as $id) {
            $this->startOne($campaign, $append, (int) $id);
        }

        if ($ids->count() === self::CHUNK_SIZE) {
            ProcessCompletedCampaignAppendChunkJob::dispatch(
                $appendId,
                (int) $ids->last(),
            )->afterCommit();
        }
    }

    private function startOne(Campaign $campaign, CampaignMessageChainAppend $append, int $enrollmentId): void
    {
        DB::transaction(function () use ($campaign, $append, $enrollmentId): void {
            // Use the same family lock order as normal Campaign enrollment so an
            // operator append cannot displace an existing family participant.
            $family = Campaign::query()
                ->when(
                    is_string($campaign->family_key) && trim($campaign->family_key) !== '',
                    fn (Builder $query) => $query->where('family_key', $campaign->family_key),
                    fn (Builder $query) => $query->whereKey($campaign->getKey()),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedCampaign = $family->firstWhere('id', $campaign->getKey());

            if (! $lockedCampaign instanceof Campaign || ! $this->isCurrent($lockedCampaign, $append)) {
                return;
            }

            $original = $this->candidates($lockedCampaign, $append)
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->first();
            $contact = $original?->contact;

            if (! $original instanceof CampaignEnrollment || ! $contact instanceof Contact) {
                return;
            }

            if (CampaignEnrollment::query()
                ->where('contact_id', $contact->getKey())
                ->whereIn('campaign_id', $family->modelKeys())
                ->whereHas('messageChainEnrollment', fn (Builder $query) => $query
                    ->whereIn('status', [
                        MessageChainEnrollment::STATUS_ACTIVE,
                        MessageChainEnrollment::STATUS_PAUSED,
                    ]))
                ->exists()
            ) {
                return;
            }

            if (($lockedCampaign->hasEligibilityCriteria() || $lockedCampaign->usesAutomaticEnrollment())
                && ! $this->eligibility->eligible($lockedCampaign, $contact)
            ) {
                return;
            }

            $step = $lockedCampaign->messageChain?->currentVersion?->steps()
                ->where('key', $append->appended_step_key)
                ->where('is_active', true)
                ->first();

            if (! $step instanceof MessageChainStep
                || $this->priorReceipts->recorded($contact, $lockedCampaign, (string) $step->key)
                || ! $step->variants()
                    ->where('is_active', true)
                    ->get()
                    ->contains(fn ($variant): bool => $this->messageGate->allows(
                        contact: $contact,
                        channel: $variant->channel,
                        purpose: $variant->purpose,
                        scope: $variant->scope,
                        messageKey: $variant->message_type,
                    ))
            ) {
                return;
            }

            $this->enrollContact->handle(
                contact: $contact,
                campaignKey: (string) $lockedCampaign->key,
                source: $lockedCampaign,
                meta: ['completed_append' => [
                    'append_id' => (int) $append->getKey(),
                    'original_enrollment_id' => (int) $original->getKey(),
                ]],
                startContext: array_replace(
                    is_array($original->start_context) ? $original->start_context : [],
                    ['completed_append_id' => (int) $append->getKey()],
                ),
                entryKey: 'completed_append:'.$append->getKey().':'.$contact->getKey(),
                eagerProcess: false,
                startStepKey: (string) $step->key,
            );
        }, 3);
    }

    private function candidates(Campaign $campaign, CampaignMessageChainAppend $append): Builder
    {
        return CampaignEnrollment::query()
            ->where('campaign_enrollments.campaign_id', $campaign->getKey())
            ->whereHas('contact')
            ->whereHas('messageChainEnrollment', fn (Builder $query) => $query
                ->where('status', MessageChainEnrollment::STATUS_COMPLETED)
                ->where('message_chain_version_id', $append->from_message_chain_version_id)
                ->where('completed_at', '<=', $append->created_at))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('campaign_enrollments as later')
                ->whereColumn('later.contact_id', 'campaign_enrollments.contact_id')
                ->where('later.campaign_id', $campaign->getKey())
                ->whereColumn('later.id', '>', 'campaign_enrollments.id'));
    }

    private function currentAppend(Campaign $campaign): ?CampaignMessageChainAppend
    {
        if (! $campaign->isActive() || ! $campaign->messageChain?->isActive()) {
            return null;
        }

        $versionId = $campaign->messageChain->current_version_id;

        return CampaignMessageChainAppend::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('to_message_chain_version_id', $versionId)
            ->first();
    }

    private function isCurrent(Campaign $campaign, CampaignMessageChainAppend $append): bool
    {
        return $campaign->isActive()
            && (int) $campaign->getKey() === (int) $append->campaign_id
            && (int) ($campaign->messageChain?->current_version_id ?? 0)
                === (int) $append->to_message_chain_version_id
            && $campaign->messageChain?->isActive();
    }
}