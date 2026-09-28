<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Data\CampaignContactResultOperation;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplyCampaignContactResultOperationAction
{
    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollSequential,
        private readonly EnrollContactInCampaignAllocationAction $enrollAllocation,
        private readonly ReenrollContactInCampaignAction $reenrollSequential,
        private readonly ReenrollContactInCampaignAllocationAction $reenrollAllocation,
        private readonly ExcludeContactFromCampaignAllocationMessageAction $excludeAllocationMessage,
        private readonly RemoveContactCampaignAllocationMessageExclusionAction $removeAllocationMessageExclusion,
    ) {}

    public function handle(
        Campaign $campaign,
        Contact $contact,
        User $actor,
        string $operation,
        string $operationId,
        ?string $messageStepKey = null,
        ?string $reason = null,
    ): void {
        $operation = CampaignContactResultOperation::normalize($operation);
        $operationId = trim($operationId);
        $messageStepKey = $this->nullableString($messageStepKey);

        if ($operationId === '') {
            throw new InvalidArgumentException(
                'Campaign Contact-result operation identity cannot be empty.',
            );
        }

        if (CampaignContactResultOperation::requiresMessageStep($operation)
            && $messageStepKey === null
        ) {
            throw new InvalidArgumentException(
                'This Campaign Contact-result operation requires a message.',
            );
        }

        $meta = [
            'source' => 'contact_result_action',
            'actor_user_id' => (int) $actor->getKey(),
            'operation_id' => $operationId,
            'operation' => $operation,
            'message_step_key' => $messageStepKey,
        ];
        $entryKey = implode(':', [
            'contact_result_action',
            $operationId,
            $operation,
        ]);

        switch ($operation) {
            case CampaignContactResultOperation::ENROLL:
                $this->enroll(
                    campaign: $campaign,
                    contact: $contact,
                    actor: $actor,
                    entryKey: $entryKey,
                    meta: $meta,
                );

                return;

            case CampaignContactResultOperation::REENROLL_FROM_MESSAGE:
                $this->reenroll(
                    campaign: $campaign,
                    contact: $contact,
                    actor: $actor,
                    entryKey: $entryKey,
                    messageStepKey: (string) $messageStepKey,
                    meta: $meta,
                );

                return;

            case CampaignContactResultOperation::EXCLUDE_ALLOCATION_MESSAGE:
                $this->excludeAllocationMessage->handle(
                    contact: $contact,
                    campaignKey: (string) $campaign->key,
                    messageStepKey: (string) $messageStepKey,
                    source: $actor,
                    excludedBy: $actor,
                    reason: $reason,
                );

                return;

            case CampaignContactResultOperation::REMOVE_ALLOCATION_MESSAGE_EXCLUSION:
                $this->removeAllocationMessageExclusion->handle(
                    contact: $contact,
                    campaignKey: (string) $campaign->key,
                    messageStepKey: (string) $messageStepKey,
                );

                return;

            case CampaignContactResultOperation::ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION:
                $this->enrollWithAllocationMessageExclusion(
                    campaign: $campaign,
                    contact: $contact,
                    actor: $actor,
                    entryKey: $entryKey,
                    messageStepKey: (string) $messageStepKey,
                    reason: $reason,
                    meta: $meta,
                );

                return;
        }
    }

    /** @param array<string, mixed> $meta */
    private function enroll(
        Campaign $campaign,
        Contact $contact,
        User $actor,
        string $entryKey,
        array $meta,
    ): void {
        if ($campaign->usesSequentialExecution()) {
            $this->enrollSequential->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                source: $actor,
                meta: $meta,
                startContext: $meta,
                entryKey: $entryKey,
                eagerProcess: true,
            );

            return;
        }

        if ($campaign->usesRecurringAllocation()) {
            $this->enrollAllocation->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                source: $actor,
                meta: $meta,
                entryKey: $entryKey,
            );

            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Campaign [%s] uses unsupported execution strategy [%s].',
            (string) $campaign->key,
            (string) $campaign->execution_strategy,
        ));
    }

    /** @param array<string, mixed> $meta */
    private function reenroll(
        Campaign $campaign,
        Contact $contact,
        User $actor,
        string $entryKey,
        string $messageStepKey,
        array $meta,
    ): void {
        if ($campaign->usesSequentialExecution()) {
            $this->reenrollSequential->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                startMessageStepKey: $messageStepKey,
                entryKey: $entryKey,
                source: $actor,
                meta: $meta,
            );

            return;
        }

        if ($campaign->usesRecurringAllocation()) {
            $this->reenrollAllocation->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                startMessageStepKey: $messageStepKey,
                entryKey: $entryKey,
                source: $actor,
                meta: $meta,
            );

            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Campaign [%s] uses unsupported execution strategy [%s].',
            (string) $campaign->key,
            (string) $campaign->execution_strategy,
        ));
    }

    /** @param array<string, mixed> $meta */
    private function enrollWithAllocationMessageExclusion(
        Campaign $campaign,
        Contact $contact,
        User $actor,
        string $entryKey,
        string $messageStepKey,
        ?string $reason,
        array $meta,
    ): void {
        if (! $campaign->usesRecurringAllocation()) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] must use recurring allocation for an allocation message exclusion.',
                (string) $campaign->key,
            ));
        }

        DB::transaction(function () use (
            $campaign,
            $contact,
            $actor,
            $entryKey,
            $messageStepKey,
            $reason,
            $meta,
        ): void {
            $this->excludeAllocationMessage->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                messageStepKey: $messageStepKey,
                source: $actor,
                excludedBy: $actor,
                reason: $reason,
            );

            $this->enrollAllocation->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                source: $actor,
                meta: $meta,
                entryKey: $entryKey,
            );
        }, 3);
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}