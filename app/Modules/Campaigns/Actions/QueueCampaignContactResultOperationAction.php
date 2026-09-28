<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Access\CampaignsAccessCapabilityContributor;
use App\Modules\Campaigns\Data\CampaignContactResultOperation;
use App\Modules\Campaigns\Jobs\ProcessCampaignContactResultOperationChunkJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Access\Services\UserAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class QueueCampaignContactResultOperationAction
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly CampaignMessageStepResolver $messageSteps,
        private readonly UserAccessService $access,
    ) {}

    /**
     * @param array<int, int> $contactIds
     * @return array{operation_id: string, contact_count: int, chunk_count: int}
     */
    public function handle(
        Campaign $campaign,
        array $contactIds,
        User $actor,
        string $operation,
        ?string $messageStepKey = null,
        ?string $reason = null,
        ?string $operationId = null,
    ): array {
        if (! $this->access->allows(
            $actor,
            CampaignsAccessCapabilityContributor::ENROLL_CONTACT_RESULTS,
        )) {
            throw new AuthorizationException(
                'This user cannot manage Campaign participation for Contact result sets.',
            );
        }

        $campaign = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', Campaign::STATUS_ACTIVE)
            ->first();

        if (! $campaign instanceof Campaign) {
            throw new InvalidArgumentException(
                'An active Campaign is required for this Contact-result operation.',
            );
        }

        $operation = CampaignContactResultOperation::normalize($operation);
        $this->assertCampaignCompatibility($campaign, $operation);

        $messageStepKey = $this->nullableString($messageStepKey);

        if (CampaignContactResultOperation::requiresMessageStep($operation)) {
            if ($messageStepKey === null) {
                throw new InvalidArgumentException(
                    'Choose a Campaign message for this Contact-result operation.',
                );
            }

            $this->messageSteps->activeStep($campaign, $messageStepKey);
        }

        $contactIds = $this->normalizeContactIds($contactIds);

        if ($contactIds === []) {
            throw new InvalidArgumentException(
                'At least one Contact is required for this Campaign operation.',
            );
        }

        $operationId = $this->operationId($operationId);
        $chunks = array_chunk($contactIds, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            ProcessCampaignContactResultOperationChunkJob::dispatch(
                campaignKey: (string) $campaign->key,
                contactIds: $chunk,
                operation: $operation,
                operationId: $operationId,
                actorUserId: (int) $actor->getKey(),
                messageStepKey: $messageStepKey,
                reason: $this->nullableReason($reason),
            );
        }

        return [
            'operation_id' => $operationId,
            'contact_count' => count($contactIds),
            'chunk_count' => count($chunks),
        ];
    }

    private function assertCampaignCompatibility(
        Campaign $campaign,
        string $operation,
    ): void {
        if (! $campaign->usesSequentialExecution()
            && ! $campaign->usesRecurringAllocation()
        ) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] uses unsupported execution strategy [%s].',
                (string) $campaign->key,
                (string) $campaign->execution_strategy,
            ));
        }

        if (CampaignContactResultOperation::requiresRecurringAllocation($operation)
            && ! $campaign->usesRecurringAllocation()
        ) {
            throw new InvalidArgumentException(
                'This Contact-result operation requires a recurring-allocation Campaign.',
            );
        }

        if ($campaign->usesRecurringAllocation()
            && is_string($campaign->family_key)
            && trim($campaign->family_key) !== ''
        ) {
            throw new InvalidArgumentException(
                'Recurring-allocation Campaigns cannot use Campaign-family arbitration yet.',
            );
        }
    }

    /** @param array<int, int> $contactIds @return array<int, int> */
    private function normalizeContactIds(array $contactIds): array
    {
        return collect($contactIds)
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function operationId(?string $operationId): string
    {
        if (! is_string($operationId) || trim($operationId) === '') {
            return (string) Str::uuid();
        }

        $operationId = trim($operationId);

        if (mb_strlen($operationId) > 128) {
            throw new InvalidArgumentException(
                'Campaign Contact-result operation identity cannot exceed 128 characters.',
            );
        }

        return $operationId;
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function nullableReason(?string $reason): ?string
    {
        $reason = $this->nullableString($reason);

        return $reason === null ? null : mb_substr($reason, 0, 255);
    }
}