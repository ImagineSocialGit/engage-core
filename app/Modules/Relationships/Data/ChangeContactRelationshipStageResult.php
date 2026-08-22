<?php

namespace App\Modules\Relationships\Data;

use App\Modules\Relationships\Models\ContactRelationship;

final readonly class ChangeContactRelationshipStageResult
{
    public function __construct(
        public ?ContactRelationship $relationship,
        public ?string $previousStageKey,
        public bool $guardMatched,
        public bool $changed,
    ) {}
}