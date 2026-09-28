<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Modules\Events\Services\EventDuplicateDetector;
use App\Modules\Events\Services\EventReadinessRegistry;
use Illuminate\Support\Facades\DB;

final class PromoteEventAction
{
    public function __construct(
        private readonly EventReadinessRegistry $readiness,
        private readonly EventDuplicateDetector $duplicates,
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    public function handle(
        Event $event,
        EventActionContext $context,
        bool $confirmDuplicate = false,
    ): Event {
        return DB::transaction(function () use (
            $event,
            $context,
            $confirmDuplicate,
        ): Event {
            $current = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            if ($current->status === EventStatus::Upcoming) {
                return $current;
            }

            if ($current->status !== EventStatus::Draft) {
                throw $this->invalidTransition($current, EventStatus::Upcoming);
            }

            $readiness = $this->readiness->evaluate(
                $current,
                CoreEventReadinessContributor::CAPABILITY,
            );

            if (! $readiness->ready()) {
                throw new EventActionBlockedException(
                    blocker: 'core_readiness_failed',
                    context: ['codes' => $readiness->codes()],
                );
            }

            $similar = $this->duplicates->similar($current);

            if ($similar->isNotEmpty() && ! $confirmDuplicate) {
                throw new EventActionBlockedException(
                    blocker: 'duplicate_confirmation_required',
                    context: [
                        'event_ids' => $similar->modelKeys(),
                    ],
                );
            }

            $fromStatus = $current->status;
            $current->status = EventStatus::Upcoming;
            $current->save();

            $this->signals->recordLifecycle(
                eventKey: EventAutomationSignalRecorder::UPCOMING,
                event: $current,
                fromStatus: $fromStatus,
                toStatus: EventStatus::Upcoming,
                context: $context,
            );

            return $current->refresh();
        }, 3);
    }

    private function invalidTransition(
        Event $event,
        EventStatus $toStatus,
    ): EventActionBlockedException {
        return new EventActionBlockedException(
            blocker: 'invalid_lifecycle_transition',
            context: [
                'from_status' => $event->status->value,
                'to_status' => $toStatus->value,
            ],
        );
    }
}