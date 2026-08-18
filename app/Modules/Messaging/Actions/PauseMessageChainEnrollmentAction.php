<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PauseMessageChainEnrollmentAction
{
    public const DEFAULT_REASON = 'message_chain_paused';

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

            if ($locked->isTerminal() || $locked->status === MessageChainEnrollment::STATUS_PAUSED) {
                return $locked;
            }

            if ($locked->status !== MessageChainEnrollment::STATUS_ACTIVE) {
                throw new InvalidArgumentException(sprintf(
                    'MessageChainEnrollment [%d] cannot be paused from status [%s].',
                    (int) $locked->getKey(),
                    (string) $locked->status,
                ));
            }

            $locked->forceFill([
                'status' => MessageChainEnrollment::STATUS_PAUSED,
                'paused_at' => now(),
            ])->save();

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