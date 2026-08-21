<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignEnrollmentArbitrator;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class EnrollContactInCampaignAction
{
    public const SURFACE = 'campaigns';

    public function __construct(
        private readonly StartMessageChainEnrollmentAction $startMessageChainEnrollment,
        private readonly CampaignEnrollmentArbitrator $enrollmentArbitrator,
    ) {}

    /**
     * Legacy caller arguments remain in the public signature while preset/FlowRoute
     * definitions finish moving to direct MessageChain authoring. MessageChainVersion
     * owns progression and exit behavior. Non-empty enrollment exitConditions are
     * rejected instead of being silently ignored; dispatchKey is compatibility-only.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed>|null $exitConditions
     */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        array $payload = [],
        ?array $meta = null,
        ?array $startContext = null,
        ?array $exitConditions = null,
        ?string $channel = null,
        ?string $purpose = null,
        ?string $scope = null,
        ?string $dispatchKey = null,
        ?string $entryKey = null,
        bool $eagerProcess = true,
        Carbon|string|null $initialActionAt = null,
    ): CampaignEnrollment {
        if ($exitConditions !== null && $exitConditions !== []) {
            throw new InvalidArgumentException(
                'Campaign enrollment exit conditions are no longer supported. Configure exit conditions on the selected MessageChainVersion.',
            );
        }

        $entryKey = $this->normalizeEntryKey($entryKey);

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $source,
            $payload,
            $meta,
            $startContext,
            $channel,
            $purpose,
            $scope,
            $entryKey,
            $eagerProcess,
            $initialActionAt,
        ): CampaignEnrollment {
            $candidate = $this->resolveCampaign(
                campaignKey: $campaignKey,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            );

            if ($entryKey !== null) {
                $existingEntry = $this->existingEntry(
                    contact: $contact,
                    campaign: $candidate,
                    entryKey: $entryKey,
                );

                if ($existingEntry instanceof CampaignEnrollment) {
                    return $existingEntry;
                }
            }

            if (! $candidate->isActive()) {
                throw CampaignUnavailableForEnrollmentException::inactive(
                    campaignKey: $candidate->key,
                    campaignStatus: $candidate->status,
                );
            }

            $arbitration = $this->enrollmentArbitrator->handle(
                contact: $contact,
                candidate: $candidate,
                source: $source,
            );
            $campaign = $arbitration->campaign;

            if ($arbitration->existingEnrollment instanceof CampaignEnrollment) {
                return $arbitration->existingEnrollment;
            }

            $messageChain = $this->messageChain($campaign);
            $startedAt = Carbon::now()->utc();
            $enrollmentMeta = $this->withArbitrationMeta(
                meta: $meta,
                arbitrationMeta: $arbitration->enrollmentMeta(),
            );

            $dedupeKey = $entryKey !== null
                ? $this->entryDedupeKey($candidate, $contact, $entryKey)
                : null;

            $enrollment = CampaignEnrollment::query()->create([
                'contact_id' => $contact->getKey(),
                'campaign_id' => $campaign->getKey(),
                'message_chain_enrollment_id' => null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'campaign_key' => $campaign->key,
                'dedupe_key' => $dedupeKey,
                'start_context' => $this->startContextWithPayload(
                    startContext: $startContext,
                    payload: $payload,
                    entryKey: $entryKey,
                ),
                'started_at' => $startedAt,
                'meta' => $enrollmentMeta,
            ]);

            if ($dedupeKey === null) {
                $dedupeKey = $this->dedupeKey($campaign, $enrollment);

                $enrollment->forceFill([
                    'dedupe_key' => $dedupeKey,
                ])->save();
            }

            $messageChainEnrollment = $this->startMessageChainEnrollment->handle(
                messageChain: $messageChain,
                recipient: $contact,
                dedupeKey: $dedupeKey,
                context: $enrollment,
                origin: $campaign,
                startedAt: $startedAt,
                surface: self::SURFACE,
                eagerProcess: $eagerProcess,
                initialActionAt: $initialActionAt,
            );

            $enrollment->forceFill([
                'message_chain_enrollment_id' => $messageChainEnrollment->getKey(),
            ])->save();
            $enrollment->setRelation('messageChainEnrollment', $messageChainEnrollment);

            return $enrollment
                ->refresh()
                ->load('messageChainEnrollment');
        }, 3);
    }

    private function resolveCampaign(
        string $campaignKey,
        ?string $channel = null,
        ?string $purpose = null,
        ?string $scope = null,
    ): Campaign {
        $query = Campaign::query()->where('key', $campaignKey);

        if ($channel !== null) {
            $query->where('channel', $this->normalizeSegment($channel));
        }

        if ($purpose !== null) {
            $query->where('purpose', $this->normalizeSegment($purpose));
        }

        if ($scope !== null) {
            $query->where('scope', $this->normalizeSegment($scope));
        }

        $campaign = $query->first();

        if (! $campaign instanceof Campaign) {
            throw CampaignUnavailableForEnrollmentException::missing($campaignKey);
        }

        return $campaign;
    }

    private function messageChain(Campaign $campaign): MessageChain
    {
        if (! is_numeric($campaign->message_chain_id) || (int) $campaign->message_chain_id < 1) {
            throw new RuntimeException(
                "Active Campaign [{$campaign->key}] has no selected MessageChain.",
            );
        }

        $messageChain = MessageChain::query()
            ->with('currentVersion.steps')
            ->whereKey((int) $campaign->message_chain_id)
            ->lockForUpdate()
            ->first();

        if (! $messageChain instanceof MessageChain) {
            throw new RuntimeException(
                "Campaign [{$campaign->key}] references missing MessageChain [{$campaign->message_chain_id}].",
            );
        }

        return $messageChain;
    }

    private function existingEntry(
        Contact $contact,
        Campaign $campaign,
        string $entryKey,
    ): ?CampaignEnrollment {
        $dedupeKey = $this->entryDedupeKey($campaign, $contact, $entryKey);

        $enrollment = CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->first();

        if (! $enrollment instanceof CampaignEnrollment) {
            return null;
        }

        if ((int) $enrollment->contact_id !== (int) $contact->getKey()
            || (int) $enrollment->campaign_id !== (int) $campaign->getKey()
        ) {
            throw new RuntimeException(
                'Campaign entry key resolved to a conflicting enrollment identity.',
            );
        }

        return $enrollment;
    }

    private function entryDedupeKey(
        Campaign $campaign,
        Contact $contact,
        string $entryKey,
    ): string {
        return implode(':', [
            'campaign_entry',
            (int) $campaign->getKey(),
            (int) $contact->getKey(),
            hash('sha256', $entryKey),
        ]);
    }

    private function dedupeKey(
        Campaign $campaign,
        CampaignEnrollment $enrollment,
    ): string {
        return implode(':', [
            'campaign',
            (int) $campaign->getKey(),
            'enrollment',
            (int) $enrollment->getKey(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function startContextWithPayload(
        ?array $startContext,
        array $payload,
        ?string $entryKey = null,
    ): ?array {
        if ($payload === [] && $entryKey === null) {
            return $startContext;
        }

        $startContext ??= [];

        if ($entryKey !== null) {
            $startContext['entry_key'] = $entryKey;
        }
        if ($payload !== []) {
            $existingPayload = is_array($startContext['payload'] ?? null)
                ? $startContext['payload']
                : [];

            $startContext['payload'] = array_replace_recursive(
                $existingPayload,
                $payload,
            );
        }

        return $startContext;
    }

    /**
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed> $arbitrationMeta
     * @return array<string, mixed>|null
     */
    private function withArbitrationMeta(?array $meta, array $arbitrationMeta): ?array
    {
        if ($arbitrationMeta === []) {
            return $meta;
        }

        return array_replace_recursive(
            $meta ?? [],
            $arbitrationMeta,
        );
    }


    private function normalizeEntryKey(?string $entryKey): ?string
    {
        if (! is_string($entryKey) || trim($entryKey) === '') {
            return null;
        }

        $entryKey = trim($entryKey);

        if (mb_strlen($entryKey) > 255) {
            throw new InvalidArgumentException(
                'Campaign entry key cannot exceed 255 characters.',
            );
        }

        return $entryKey;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}