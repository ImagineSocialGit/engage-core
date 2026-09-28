<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Modules\Events\Services\EventReadinessRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RescheduleEventAction
{
    public function __construct(
        private readonly EventReadinessRegistry $readiness,
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    public function handle(
        Event $event,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        string $timezone,
        EventActionContext $context,
    ): Event {
        return DB::transaction(function () use (
            $event,
            $startsAt,
            $endsAt,
            $timezone,
            $context,
        ): Event {
            $current = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            $normalizedStartsAt = CarbonImmutable::instance($startsAt);
            $normalizedEndsAt = $endsAt !== null
                ? CarbonImmutable::instance($endsAt)
                : null;
            $normalizedTimezone = trim($timezone);

            if ($current->status === EventStatus::Upcoming
                && $this->sameSchedule(
                    $current,
                    $normalizedStartsAt,
                    $normalizedEndsAt,
                    $normalizedTimezone,
                )
            ) {
                return $current;
            }

            if ($current->status !== EventStatus::Postponed) {
                throw new EventActionBlockedException(
                    blocker: 'invalid_lifecycle_transition',
                    context: [
                        'from_status' => $current->status->value,
                        'to_status' => EventStatus::Upcoming->value,
                    ],
                );
            }

            $previousStartsAt = $current->starts_at?->toISOString();
            $previousEndsAt = $current->ends_at?->toISOString();
            $previousTimezone = $current->timezone;

            $current->starts_at = $normalizedStartsAt;
            $current->ends_at = $normalizedEndsAt;
            $current->timezone = $normalizedTimezone;

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

            $fromStatus = $current->status;
            $current->status = EventStatus::Upcoming;
            $current->save();

            $this->signals->recordLifecycle(
                eventKey: EventAutomationSignalRecorder::RESCHEDULED,
                event: $current,
                fromStatus: $fromStatus,
                toStatus: EventStatus::Upcoming,
                context: $context,
                payload: [
                    'previous_starts_at' => $previousStartsAt,
                    'previous_ends_at' => $previousEndsAt,
                    'previous_timezone' => $previousTimezone,
                ],
            );

            return $current->refresh();
        }, 3);
    }

    private function sameSchedule(
        Event $event,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        string $timezone,
    ): bool {
        return $event->starts_at?->equalTo($startsAt) === true
            && (
                ($event->ends_at === null && $endsAt === null)
                || ($event->ends_at?->equalTo($endsAt) === true)
            )
            && $event->timezone === $timezone;
    }
}