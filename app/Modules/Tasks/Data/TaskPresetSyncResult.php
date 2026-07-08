<?php

namespace App\Modules\Tasks\Data;

class TaskPresetSyncResult
{
    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $errors
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $removed = 0,
        public int $skipped = 0,
        public int $customizedSkipped = 0,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function created(): void
    {
        $this->created++;
    }

    public function updated(): void
    {
        $this->updated++;
    }

    public function removed(): void
    {
        $this->removed++;
    }

    public function skipped(): void
    {
        $this->skipped++;
    }

    public function customizedSkipped(): void
    {
        $this->customizedSkipped++;
        $this->skipped++;
    }

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function error(string $error): void
    {
        $this->errors[] = $error;
    }
}
