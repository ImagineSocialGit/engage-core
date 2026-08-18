<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EnrollContactInCampaignAction
{
    public const SURFACE = 'campaigns';

    public function __construct(
        private readonly StartMessageChainEnrollmentAction $startMessageChainEnrollment,
    ) {}

    /**
     * Transitional compatibility arguments such as payload/startContext/meta are
     * retained while callers and Project State still carry them. MessageChain owns
     * progression from this point forward; dispatchKey no longer selects a CampaignStep.
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
    ): CampaignEnrollment {
        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $source,
            $payload,
            $meta,
            $startContext,
            $exitConditions,
            $channel,
            $purpose,
            $scope,
        ): CampaignEnrollment {
            $campaign = $this->resolveCampaign(
                campaignKey: $campaignKey,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
            );

            $existingEnrollment = $this->existingOpenEnrollment(
                contact: $contact,
                campaign: $campaign,
            );

            if ($existingEnrollment instanceof CampaignEnrollment) {
                return $existingEnrollment;
            }

            $this->assertNoUnlinkedLegacyOpenEnrollment(
                contact: $contact,
                campaign: $campaign,
            );

            $messageChain = $this->messageChain($campaign);
            $startedAt = Carbon::now()->utc();

            $enrollment = CampaignEnrollment::query()->create([
                'contact_id' => $contact->getKey(),
                'campaign_id' => $campaign->getKey(),
                'message_chain_enrollment_id' => null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'campaign_key' => $campaign->key,
                'status' => CampaignEnrollment::STATUS_ACTIVE,
                'current_step' => null,
                'current_campaign_step_id' => null,
                'start_context' => $this->startContextWithPayload($startContext, $payload),
                'exit_conditions' => $exitConditions,
                'last_scheduled_message_id' => null,
                'started_at' => $startedAt,
                'meta' => $meta,
            ]);

            $dedupeKey = $this->dedupeKey($campaign, $enrollment);

            $enrollment->forceFill([
                'dedupe_key' => $dedupeKey,
            ])->save();

            $messageChainEnrollment = $this->startMessageChainEnrollment->handle(
                messageChain: $messageChain,
                recipient: $contact,
                dedupeKey: $dedupeKey,
                context: $enrollment,
                origin: $campaign,
                startedAt: $startedAt,
                surface: self::SURFACE,
            );

            $this->linkMessageChainEnrollment(
                campaignEnrollment: $enrollment,
                messageChainEnrollment: $messageChainEnrollment,
            );

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
        $query = Campaign::query()
            ->where('key', $campaignKey);

        if ($channel !== null) {
            $query->where('channel', $this->normalizeSegment($channel));
        }

        if ($purpose !== null) {
            $query->where('purpose', $this->normalizeSegment($purpose));
        }

        if ($scope !== null) {
            $query->where('scope', $this->normalizeSegment($scope));
        }

        $campaign = $query
            ->lockForUpdate()
            ->first();

        if (! $campaign instanceof Campaign) {
            throw CampaignUnavailableForEnrollmentException::missing($campaignKey);
        }

        if (! $campaign->isActive()) {
            throw CampaignUnavailableForEnrollmentException::inactive(
                campaignKey: $campaign->key,
                campaignStatus: $campaign->status,
            );
        }

        return $campaign;
    }

    private function existingOpenEnrollment(
        Contact $contact,
        Campaign $campaign,
    ): ?CampaignEnrollment {
        return CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('contact_id', $contact->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function assertNoUnlinkedLegacyOpenEnrollment(
        Contact $contact,
        Campaign $campaign,
    ): void {
        $legacyEnrollment = CampaignEnrollment::query()
            ->where('contact_id', $contact->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->whereNull('message_chain_enrollment_id')
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->lockForUpdate()
            ->first();

        if (! $legacyEnrollment instanceof CampaignEnrollment) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Campaign [%s] has an open legacy CampaignEnrollment [%d] without a MessageChainEnrollment link. In-flight legacy enrollment conversion is intentionally unsupported by this cutover.',
            $campaign->key,
            (int) $legacyEnrollment->getKey(),
        ));
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

    private function linkMessageChainEnrollment(
        CampaignEnrollment $campaignEnrollment,
        MessageChainEnrollment $messageChainEnrollment,
    ): void {
        $attributes = [
            'message_chain_enrollment_id' => $messageChainEnrollment->getKey(),
        ];

        // These fields remain only as compatibility projection until F7 removes
        // Campaign-owned progression state from readers/schema. MessageChainEnrollment
        // is authoritative from this cutover forward.
        if ($messageChainEnrollment->status === MessageChainEnrollment::STATUS_COMPLETED) {
            $completedAt = $messageChainEnrollment->completed_at ?? now();

            $attributes = [
                ...$attributes,
                'status' => CampaignEnrollment::STATUS_COMPLETED,
                'completed_at' => $completedAt,
                'exited_at' => $completedAt,
                'exit_reason' => CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            ];
        }

        $campaignEnrollment->forceFill($attributes)->save();
        $campaignEnrollment->setRelation(
            'messageChainEnrollment',
            $messageChainEnrollment,
        );
    }

    /**
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function startContextWithPayload(?array $startContext, array $payload): ?array
    {
        if ($payload === []) {
            return $startContext;
        }

        $startContext ??= [];
        $existingPayload = is_array($startContext['payload'] ?? null)
            ? $startContext['payload']
            : [];

        $startContext['payload'] = array_replace_recursive(
            $existingPayload,
            $payload,
        );

        return $startContext;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}