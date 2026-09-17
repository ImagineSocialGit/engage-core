<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\Webinar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ReconcileWebinarMessageScheduleAction
{
    /**
     * The caller holds a transaction and the webinar row lock. Dispatches run after commit.
     *
     * @return array{enrollments: int, messages: int, review_required: int, review_enrollment_ids: array<int, int>, review_message_ids: array<int, int>}
     */
    public function handle(
        Webinar $webinar,
        CarbonInterface $previousStart,
        CarbonInterface $currentStart,
    ): array {
        $previousStart = Carbon::parse($previousStart)->utc();
        $currentStart = Carbon::parse($currentStart)->utc();
        $result = [
            'enrollments' => 0,
            'messages' => 0,
            'review_required' => 0,
            'review_enrollment_ids' => [],
            'review_message_ids' => [],
        ];

        if ($previousStart->equalTo($currentStart)) {
            return $result;
        }

        MessageChainEnrollment::query()
            ->where('origin_type', $webinar->getMorphClass())
            ->where('origin_id', $webinar->getKey())
            ->where(function ($query): void {
                $query->whereNotNull('next_action_at')
                    ->orWhereHas('scheduledMessages', fn ($messages) => $messages
                        ->where('status', ScheduledMessage::STATUS_PENDING));
            })
            ->chunkById(200, function ($enrollments) use (
                $previousStart,
                $currentStart,
                &$result,
            ): void {
                foreach ($enrollments as $candidate) {
                    $enrollment = MessageChainEnrollment::query()
                        ->whereKey($candidate->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $enrollment instanceof MessageChainEnrollment) {
                        continue;
                    }

                    $step = $enrollment->currentMessageChainStep;

                    if ($enrollment->next_action_at !== null
                        && $this->anchoredToWebinar($step)
                    ) {
                        $oldDue = $previousStart->copy()->addSeconds((int) $step->offset_seconds);
                        $newDue = $currentStart->copy()->addSeconds((int) $step->offset_seconds);

                        if ($enrollment->status !== MessageChainEnrollment::STATUS_ACTIVE
                            || ! $enrollment->next_action_at->equalTo($oldDue)
                        ) {
                            $result['review_required']++;
                            $result['review_enrollment_ids'][] = (int) $enrollment->getKey();
                        } else {
                            $enrollment->forceFill(['next_action_at' => $newDue])->save();
                            $result['enrollments']++;
                            ProcessMessageChainEnrollmentJob::dispatch(
                                enrollmentId: (int) $enrollment->getKey(),
                            )->delay($newDue)->afterCommit();
                        }
                    }

                    $messages = $enrollment->scheduledMessages()
                        ->where('status', ScheduledMessage::STATUS_PENDING)
                        ->lockForUpdate()
                        ->get();

                    foreach ($messages as $message) {
                        $variant = $message->messageChainStepVariant;
                        $messageStep = $variant?->messageChainStep;

                        if (! $this->anchoredToWebinar($messageStep)) {
                            continue;
                        }

                        $oldDue = $previousStart->copy()->addSeconds((int) $messageStep->offset_seconds);
                        $newDue = $currentStart->copy()->addSeconds((int) $messageStep->offset_seconds);

                        if ($enrollment->status !== MessageChainEnrollment::STATUS_ACTIVE
                            || $message->send_at === null
                            || ! $message->send_at->equalTo($oldDue)
                            || $message->renderContext()->exists()
                        ) {
                            $result['review_required']++;
                            $result['review_message_ids'][] = (int) $message->getKey();
                            continue;
                        }

                        $message->forceFill(['send_at' => $newDue])->save();
                        $result['messages']++;
                        SendScheduledMessageJob::dispatch(
                            scheduledMessageId: (int) $message->getKey(),
                        )->delay($newDue)->afterCommit()->onQueue($message->queue);
                    }
                }
            });

        return $result;
    }

    private function anchoredToWebinar(?MessageChainStep $step): bool
    {
        return $step instanceof MessageChainStep
            && $step->timing_type === MessageChainStep::TIMING_ANCHORED
            && $step->anchor_key === 'webinar.starts_at';
    }
}