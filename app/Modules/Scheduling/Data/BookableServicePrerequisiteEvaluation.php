<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Eloquent\Model;

final readonly class BookableServicePrerequisiteEvaluation
{
    /**
     * @param array<int, BookableServicePrerequisiteResult> $requirements
     */
    public function __construct(
        public BookableService $service,
        public Model $subject,
        public array $requirements,
    ) {}

    public function satisfied(): bool
    {
        foreach ($this->requirements as $requirement) {
            if (! $requirement->satisfied()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, BookableServicePrerequisiteResult> */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (BookableServicePrerequisiteResult $requirement): bool => ! $requirement->satisfied(),
        ));
    }
}