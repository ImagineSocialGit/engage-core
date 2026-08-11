<?php

namespace App\Modules\Forms\Data;

final class FormPresetSyncResult
{
    public function __construct(
        public int $definitionsCreated = 0,
        public int $definitionsUpdated = 0,
        public int $definitionsUnchanged = 0,
        public int $versionsPublished = 0,
        public int $versionsReused = 0,
    ) {}
}