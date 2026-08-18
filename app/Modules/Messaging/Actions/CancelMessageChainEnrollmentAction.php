<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Support\Facades\DB;

class CancelMessageChainEnrollmentAction
{
    public const DEFAULT_REASON = 'message_chain_cancelled';

    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    public function handle(
        MessageChainEnrollment|int $enrollment,
        ?string $reason = null,
        bool $skipPendingMessages = true,
    ): MessageChainEnrollment {
        $enrollmentId = $enrollment instanceof MessageChainEnrollment
            ? (int) $enrollment->getKey()
            : $enrollment;
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $enrollmentId,
            $reason,
            $skipPendingMessages,
        ): MessageChainEnrollment {
            $locked = MessageChainEnrollment::query()
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isTerminal()) {
                return $locked;
            }

            $cancelledAt = now();

            $locked->forceFill([
                'current_message_chain_step_id' => null,
                'next_action_at' => null,
                'status' => MessageChainEnrollment::STATUS_CANCELLED,
                'exit_reason_code' => $reason,
                'cancelled_at' => $locked->cancelled_at ?? $cancelledAt,
            ])->save();
            $locked->setRelation('currentMessageChainStep', null);

            if ($skipPendingMessages) {
                $this->skipScheduledMessages->forMessageChainEnrollment(
                    enrollment: $locked,
                    reason: $reason,
                );
            }

            return $locked->refresh();
        }, 3);
    }

    private function reason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return mb_substr(
            $reason !== '' ? $reason : self::DEFAULT_REASON,
            0,
            96,
        );
    }
}