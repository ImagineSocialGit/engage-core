<?php

namespace App\Modules\Messaging\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final readonly class ResolvedMessageDispatch
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public array $definition,
        public Carbon $sendAt,
        public ?Model $behaviorOwner = null,
        public ?string $occurrenceKey = null,
        public array $meta = [],
    ) {}
}
