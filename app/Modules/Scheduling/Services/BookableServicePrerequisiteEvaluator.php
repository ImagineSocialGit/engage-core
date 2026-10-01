<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\BookableServicePrerequisiteEvaluation;
use App\Modules\Scheduling\Data\BookableServicePrerequisiteResult;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServicePrerequisite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class BookableServicePrerequisiteEvaluator
{
    public function __construct(
        private readonly BookingSubjectProviderRegistry $subjects,
    ) {}

    public function evaluate(
        BookableService $service,
        Model $subject,
        ?CarbonInterface $evaluatedAt = null,
    ): BookableServicePrerequisiteEvaluation {
        if (! $service->exists || $service->getKey() === null) {
            throw new InvalidArgumentException(
                'Scheduling prerequisite evaluation requires a persisted BookableService.',
            );
        }

        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException(
                'Scheduling prerequisite evaluation requires a persisted booking subject.',
            );
        }

        $subjectKey = $service->bookingSubjectKey();
        $this->subjects->assertAccepts($subjectKey, $subject);

        $at = $evaluatedAt instanceof CarbonInterface
            ? CarbonImmutable::instance($evaluatedAt)->utc()
            : CarbonImmutable::now('UTC');

        $requirements = [];

        foreach ($service->prerequisites()
            ->where('is_active', true)
            ->with('prerequisiteService')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get() as $prerequisite
        ) {
            $requirements[] = new BookableServicePrerequisiteResult(
                prerequisite: $prerequisite,
                completedCount: $this->completedCount(
                    prerequisite: $prerequisite,
                    subjectKey: $subjectKey,
                    subject: $subject,
                    evaluatedAt: $at,
                ),
            );
        }

        return new BookableServicePrerequisiteEvaluation(
            service: $service,
            subject: $subject,
            requirements: $requirements,
        );
    }

    private function completedCount(
        BookableServicePrerequisite $prerequisite,
        string $subjectKey,
        Model $subject,
        CarbonImmutable $evaluatedAt,
    ): int {
        $prerequisiteService = $prerequisite->prerequisiteService;

        if (! $prerequisiteService instanceof BookableService) {
            throw new LogicException(
                "Scheduling prerequisite [{$prerequisite->getKey()}] references a missing appointment type.",
            );
        }

        if ((int) $prerequisite->bookable_service_id === (int) $prerequisite->prerequisite_bookable_service_id) {
            throw new LogicException(
                "Scheduling appointment type [{$prerequisite->bookable_service_id}] cannot require itself as a prerequisite.",
            );
        }

        if ($prerequisiteService->bookingSubjectKey() !== $subjectKey) {
            throw new LogicException(sprintf(
                'Scheduling prerequisite [%s] crosses booking subject types [%s] and [%s].',
                (string) $prerequisite->getKey(),
                $subjectKey,
                $prerequisiteService->bookingSubjectKey(),
            ));
        }

        $requiredCompletions = (int) $prerequisite->required_completions;

        if ($requiredCompletions < 1) {
            throw new LogicException(
                "Scheduling prerequisite [{$prerequisite->getKey()}] requires at least one completion.",
            );
        }

        $validForDays = $prerequisite->valid_for_days;

        if ($validForDays !== null && (int) $validForDays < 1) {
            throw new LogicException(
                "Scheduling prerequisite [{$prerequisite->getKey()}] validity must be at least one day when set.",
            );
        }

        $query = Appointment::query()
            ->where('bookable_service_id', $prerequisiteService->getKey())
            ->where('status', Appointment::STATUS_COMPLETED)
            ->where('primary_attendee_type', $subject->getMorphClass())
            ->where('primary_attendee_id', $subject->getKey())
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $evaluatedAt);

        if ($validForDays !== null) {
            $query->where(
                'completed_at',
                '>=',
                $evaluatedAt->subDays((int) $validForDays),
            );
        }

        return $query->count();
    }
}