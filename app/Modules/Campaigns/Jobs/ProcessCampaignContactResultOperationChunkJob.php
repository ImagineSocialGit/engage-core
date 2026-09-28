<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ProcessCampaignContactResultOperationChunkAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCampaignContactResultOperationChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<int, int> $contactIds
     */
    public function __construct(
        public readonly string $campaignKey,
        public readonly array $contactIds,
        public readonly string $operation,
        public readonly string $operationId,
        public readonly int $actorUserId,
        public readonly ?string $messageStepKey = null,
        public readonly ?string $reason = null,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(
        ProcessCampaignContactResultOperationChunkAction $processChunk,
    ): void {
        $processChunk->handle(
            campaignKey: $this->campaignKey,
            contactIds: $this->contactIds,
            operation: $this->operation,
            operationId: $this->operationId,
            actorUserId: $this->actorUserId,
            messageStepKey: $this->messageStepKey,
            reason: $this->reason,
        );
    }
}