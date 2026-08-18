<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResumeMessageChainEnrollmentAction
{
    public function handle(
        MessageChainEnrollment|int $enrollment,
    ): MessageChainEnrollment {
        $enrollmentId = $enrollment instanceof MessageChainEnrollment
            ? (int) $enrollment->getKey()
            : $enrollment;

        $result = DB::transaction(function () use ($enrollmentId): array {
            $locked = MessageChainEnrollment::query()
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isTerminal() || $locked->status === MessageChainEnrollment::STATUS_ACTIVE) {
                return [
                    'enrollment' => $locked,
                    'dispatch' => false,
                ];
            }

            if ($locked->status !== MessageChainEnrollment::STATUS_PAUSED) {
                throw new InvalidArgumentException(sprintf(
                    'MessageChainEnrollment [%d] cannot be resumed from status [%s].',
                    (int) $locked->getKey(),
                    (string) $locked->status,
                ));
            }

            $resumedAt = Carbon::now();
            $nextActionAt = $this->nextActionAtAfterResume(
                enrollment: $locked,
                resumedAt: $resumedAt,
            );

            $locked->forceFill([
                'status' => MessageChainEnrollment::STATUS_ACTIVE,
                'resumed_at' => $resumedAt,
                'next_action_at' => $nextActionAt,
            ])->save();

            return [
                'enrollment' => $locked->refresh(),
                'dispatch' => true,
            ];
        }, 3);

        /** @var MessageChainEnrollment $resolved */
        $resolved = $result['enrollment'];

        if ($result['dispatch'] === true) {
            ProcessMessageChainEnrollmentJob::dispatch(
                enrollmentId: (int) $resolved->getKey(),
            )
                ->delay($resolved->next_action_at ?? now())
                ->afterCommit();
        }

        return $resolved;
    }

    private function nextActionAtAfterResume(
        MessageChainEnrollment $enrollment,
        Carbon $resumedAt,
    ): Carbon {
        if ($enrollment->next_action_at === null) {
            // A paused current wave may have had its pending messages skipped.
            // Re-evaluate it immediately so terminal skipped messages can advance
            // the enrollment instead of leaving it stranded with no due action.
            return $resumedAt->copy();
        }

        $pausedAt = $enrollment->paused_at;

        if (! $pausedAt instanceof Carbon) {
            return $enrollment->next_action_at->copy();
        }

        $pauseSeconds = max(
            0,
            $resumedAt->getTimestamp() - $pausedAt->getTimestamp(),
        );

        return $enrollment->next_action_at
            ->copy()
            ->addSeconds($pauseSeconds);
    }
}