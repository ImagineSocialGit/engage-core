<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class BookingSubjectEligibilityContext
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public CarbonImmutable $evaluatedAt;

    /** @param array<string, mixed> $policy */
    public function __construct(
        public BookableService $service,
        public Model $subject,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        CarbonInterface $evaluatedAt,
        public array $policy,
    ) {
        if (! $service->exists || $service->getKey() === null) {
            throw new InvalidArgumentException(
                'Scheduling subject eligibility requires a persisted BookableService.',
            );
        }

        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException(
                'Scheduling subject eligibility requires a persisted booking subject.',
            );
        }

        $this->startsAt = CarbonImmutable::instance($startsAt)->utc();
        $this->endsAt = CarbonImmutable::instance($endsAt)->utc();
        $this->evaluatedAt = CarbonImmutable::instance($evaluatedAt)->utc();

        if (! $this->endsAt->greaterThan($this->startsAt)) {
            throw new InvalidArgumentException(
                'Scheduling subject eligibility requires an appointment end after its start.',
            );
        }
    }
}