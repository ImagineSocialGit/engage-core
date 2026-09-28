<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceInventoryOrchestrationResult
{
    public const OUTCOME_RECONCILED = 'reconciled';
    public const OUTCOME_AWAITING_AUTHORITY_OPERATION = 'awaiting_authority_operation';
    public const OUTCOME_ADJUSTED = 'adjusted';
    public const OUTCOME_ADJUSTMENT_IN_PROGRESS = 'adjustment_in_progress';

    public function __construct(
        public int $commerceInventoryEffectId,
        public string $outcome,
        public string $effectStatus,
        public bool $providerMutationAttempted,
        public ?string $providerKey = null,
        public ?int $commerceInventoryAdjustmentId = null,
        public ?string $adjustmentStatus = null,
    ) {
        if ($this->commerceInventoryEffectId < 1) {
            throw new InvalidArgumentException(
                'Commerce inventory orchestration requires a persisted inventory effect.',
            );
        }

        if (! in_array($this->outcome, [
            self::OUTCOME_RECONCILED,
            self::OUTCOME_AWAITING_AUTHORITY_OPERATION,
            self::OUTCOME_ADJUSTED,
            self::OUTCOME_ADJUSTMENT_IN_PROGRESS,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported Commerce inventory orchestration outcome [{$this->outcome}].",
            );
        }
    }
}