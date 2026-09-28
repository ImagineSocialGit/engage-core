<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Access\CampaignsAccessCapabilityContributor;
use App\Modules\Campaigns\Data\CampaignContactResultOperation;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ProcessCampaignContactResultOperationChunkAction
{
    public function __construct(
        private readonly ApplyCampaignContactResultOperationAction $applyOperation,
        private readonly CampaignMessageStepResolver $messageSteps,
        private readonly UserAccessService $access,
        private readonly ContactVisibility $visibility,
    ) {}

    /** @param array<int, int> $contactIds */
    public function handle(
        string $campaignKey,
        array $contactIds,
        string $operation,
        string $operationId,
        int $actorUserId,
        ?string $messageStepKey = null,
        ?string $reason = null,
    ): void {
        $actor = User::query()->find($actorUserId);

        if (! $actor instanceof User || ! $this->access->allows(
            $actor,
            CampaignsAccessCapabilityContributor::ENROLL_CONTACT_RESULTS,
        )) {
            return;
        }

        $campaign = Campaign::query()
            ->where('key', trim($campaignKey))
            ->where('status', Campaign::STATUS_ACTIVE)
            ->first();

        if (! $campaign instanceof Campaign) {
            return;
        }

        $operation = CampaignContactResultOperation::normalize($operation);

        if (CampaignContactResultOperation::requiresRecurringAllocation($operation)
            && ! $campaign->usesRecurringAllocation()
        ) {
            return;
        }

        if ($campaign->usesRecurringAllocation()
            && is_string($campaign->family_key)
            && trim($campaign->family_key) !== ''
        ) {
            return;
        }

        $messageStepKey = $this->nullableString($messageStepKey);

        if (CampaignContactResultOperation::requiresMessageStep($operation)) {
            if ($messageStepKey === null) {
                return;
            }

            try {
                $this->messageSteps->activeStep($campaign, $messageStepKey);
            } catch (InvalidArgumentException) {
                return;
            }
        }

        $contacts = $this->visibility
            ->apply(
                Contact::query()->whereIn(
                    'contacts.id',
                    $this->normalizeContactIds($contactIds),
                ),
                $actor,
            )
            ->reorder()
            ->orderBy('contacts.id')
            ->get();

        foreach ($contacts as $contact) {
            try {
                $this->applyOperation->handle(
                    campaign: $campaign,
                    contact: $contact,
                    actor: $actor,
                    operation: $operation,
                    operationId: $operationId,
                    messageStepKey: $messageStepKey,
                    reason: $reason,
                );
            } catch (CampaignUnavailableForEnrollmentException|InvalidArgumentException $exception) {
                Log::notice('Campaign Contact-result operation skipped for Contact.', [
                    'campaign_key' => (string) $campaign->key,
                    'contact_id' => (int) $contact->getKey(),
                    'actor_user_id' => (int) $actor->getKey(),
                    'operation_id' => $operationId,
                    'operation' => $operation,
                    'message_step_key' => $messageStepKey,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /** @param array<int, int> $contactIds @return array<int, int> */
    private function normalizeContactIds(array $contactIds): array
    {
        $ids = collect($contactIds)
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}