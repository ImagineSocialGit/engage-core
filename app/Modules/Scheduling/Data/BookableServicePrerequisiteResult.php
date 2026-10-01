<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\BookableServicePrerequisite;

final readonly class BookableServicePrerequisiteResult
{
    public function __construct(
        public BookableServicePrerequisite $prerequisite,
        public int $completedCount,
    ) {}

    public function requiredCompletions(): int
    {
        return max(1, (int) $this->prerequisite->required_completions);
    }

    public function satisfied(): bool
    {
        return $this->completedCount >= $this->requiredCompletions();
    }
}