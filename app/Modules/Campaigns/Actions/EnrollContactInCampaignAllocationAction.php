<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class EnrollContactInCampaignAllocationAction
{
    public function __construct(
        private readonly CampaignMessageStepResolver $messageSteps,
    ) {}

    /** @param array<string, mixed>|null $meta */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        ?array $meta = null,
        ?string $startMessageStepKey = null,
        ?string $entryKey = null,
    ): CampaignAllocationEnrollment {
        $campaignKey = trim($campaignKey);
        $entryKey = $this->normalizeEntryKey($entryKey);

        if ($campaignKey === '') {
            throw new InvalidArgumentException('Campaign key cannot be empty.');
        }

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $source,
            $meta,
            $startMessageStepKey,
            $entryKey,
        ): CampaignAllocationEnrollment {
            $campaign = Campaign::query()
                ->where('key', $campaignKey)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof Campaign) {
                throw new InvalidArgumentException(
                    "Campaign [{$campaignKey}] does not exist.",
                );
            }

            $this->assertAvailable($campaign);
            $this->assertMessageSchedule($campaign, $startMessageStepKey);

            $dedupeKey = $entryKey !== null
                ? $this->dedupeKey($campaign, $contact, $entryKey)
                : null;

            if ($dedupeKey !== null) {
                $existingEntry = CampaignAllocationEnrollment::query()
                    ->where('dedupe_key', $dedupeKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingEntry instanceof CampaignAllocationEnrollment) {
                    $this->assertIdentity($existingEntry, $campaign, $contact);

                    return $existingEntry;
                }
            }

            $existing = CampaignAllocationEnrollment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CampaignAllocationEnrollment) {
                return $existing;
            }

            return CampaignAllocationEnrollment::query()->create([
                'contact_id' => $contact->getKey(),
                'campaign_id' => $campaign->getKey(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'start_message_step_key' => $this->nullableStepKey($startMessageStepKey),
                'status' => CampaignAllocationEnrollment::STATUS_ACTIVE,
                'dedupe_key' => $dedupeKey,
                'started_at' => now(),
                'completed_at' => null,
                'cancelled_at' => null,
                'meta' => $this->withEntryMeta($meta, $entryKey),
            ]);
        }, 3);
    }

    public function dedupeKey(
        Campaign $campaign,
        Contact $contact,
        string $entryKey,
    ): string {
        $entryKey = $this->normalizeEntryKey($entryKey);

        if ($entryKey === null) {
            throw new InvalidArgumentException('Allocation entry key cannot be empty.');
        }

        return implode(':', [
            'campaign_allocation_entry',
            (int) $campaign->getKey(),
            (int) $contact->getKey(),
            hash('sha256', $entryKey),
        ]);
    }

    private function assertAvailable(Campaign $campaign): void
    {
        if (! $campaign->isActive()) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] must be active before Contacts can enter recurring allocation.',
                (string) $campaign->key,
            ));
        }

        if ($campaign->execution_strategy
            !== Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
        ) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] does not use recurring allocation.',
                (string) $campaign->key,
            ));
        }

        if (is_string($campaign->family_key) && trim($campaign->family_key) !== '') {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] cannot use recurring allocation until Campaign-family arbitration supports allocation participation.',
                (string) $campaign->key,
            ));
        }
    }

    private function assertMessageSchedule(
        Campaign $campaign,
        ?string $startMessageStepKey,
    ): void {
        $steps = $this->messageSteps->activeSteps($campaign);

        if ($steps->isEmpty()) {
            throw new RuntimeException(sprintf(
                'Campaign [%s] has no active current messages for recurring allocation.',
                (string) $campaign->key,
            ));
        }

        if ($this->nullableStepKey($startMessageStepKey) !== null) {
            $this->messageSteps->activeStep(
                $campaign,
                (string) $startMessageStepKey,
            );
        }
    }

    private function assertIdentity(
        CampaignAllocationEnrollment $enrollment,
        Campaign $campaign,
        Contact $contact,
    ): void {
        if ((int) $enrollment->campaign_id !== (int) $campaign->getKey()
            || (int) $enrollment->contact_id !== (int) $contact->getKey()
        ) {
            throw new RuntimeException(
                'Recurring allocation entry key resolved to a conflicting enrollment identity.',
            );
        }
    }

    private function normalizeEntryKey(?string $entryKey): ?string
    {
        if (! is_string($entryKey) || trim($entryKey) === '') {
            return null;
        }

        $entryKey = trim($entryKey);

        if (mb_strlen($entryKey) > 255) {
            throw new InvalidArgumentException(
                'Allocation entry key cannot exceed 255 characters.',
            );
        }

        return $entryKey;
    }

    private function nullableStepKey(?string $stepKey): ?string
    {
        if (! is_string($stepKey) || trim($stepKey) === '') {
            return null;
        }

        return trim($stepKey);
    }

    /** @param array<string, mixed>|null $meta @return array<string, mixed>|null */
    private function withEntryMeta(?array $meta, ?string $entryKey): ?array
    {
        if ($entryKey === null) {
            return $meta;
        }

        return array_replace_recursive($meta ?? [], [
            'lifecycle' => [
                'entry_key' => $entryKey,
            ],
        ]);
    }
}