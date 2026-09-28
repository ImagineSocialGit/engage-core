<?php

namespace App\Modules\Events\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Modules\Events\Services\EventDefinitionRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RecordEventAttendanceAction
{
    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    public function handle(
        Event $event,
        Contact $contact,
        EventAttendanceStatus|string $status,
        CarbonInterface $observedAt,
        string $sourceKey,
        ?string $sourceReference = null,
    ): EventAttendance {
        $status = $this->status($status);
        $sourceKey = trim($sourceKey);
        $sourceReference = $this->nullableString($sourceReference);
        $observedAt = CarbonImmutable::instance($observedAt);

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_ATTENDANCE_SOURCE,
            $sourceKey,
        )) {
            throw new EventActionBlockedException(
                blocker: 'attendance_source_unregistered',
                context: ['source_key' => $sourceKey],
            );
        }

        return DB::transaction(function () use (
            $event,
            $contact,
            $status,
            $observedAt,
            $sourceKey,
            $sourceReference,
        ): EventAttendance {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            $attendance = EventAttendance::withTrashed()
                ->where('event_id', $currentEvent->getKey())
                ->where('contact_id', $contact->getKey())
                ->lockForUpdate()
                ->first();

            if ($attendance instanceof EventAttendance
                && $attendance->observed_at !== null
                && $attendance->observed_at->greaterThan($observedAt)
            ) {
                return $attendance;
            }

            if ($attendance instanceof EventAttendance
                && $attendance->deleted_at === null
                && $attendance->status === $status
                && $attendance->observed_at?->equalTo($observedAt) === true
                && $attendance->source_key === $sourceKey
                && $attendance->source_reference === $sourceReference
            ) {
                return $attendance;
            }

            if (! $attendance instanceof EventAttendance) {
                $attendance = new EventAttendance([
                    'event_id' => $currentEvent->getKey(),
                    'contact_id' => $contact->getKey(),
                ]);
            } elseif ($attendance->trashed()) {
                $attendance->restore();
            }

            $attendance->fill([
                'status' => $status,
                'observed_at' => $observedAt,
                'source_key' => $sourceKey,
                'source_reference' => $sourceReference,
            ]);
            $attendance->save();

            $this->signals->recordAttendance(
                attendance: $attendance,
                context: new EventActionContext(
                    source: $sourceKey,
                    occurredAt: $observedAt,
                    meta: [
                        'attendance_source_key' => $sourceKey,
                    ],
                ),
            );

            return $attendance->refresh();
        }, 3);
    }

    private function status(
        EventAttendanceStatus|string $status,
    ): EventAttendanceStatus {
        if ($status instanceof EventAttendanceStatus) {
            return $status;
        }

        $resolved = EventAttendanceStatus::tryFrom(trim($status));

        if (! $resolved instanceof EventAttendanceStatus) {
            throw new EventActionBlockedException(
                blocker: 'attendance_status_invalid',
                context: ['status' => $status],
            );
        }

        return $resolved;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}