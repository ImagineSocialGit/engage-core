<?php

namespace App\Modules\Events\Exceptions;

use RuntimeException;

final class EventActionBlockedException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $blocker,
        public readonly array $context = [],
    ) {
        parent::__construct("Event action blocked [{$blocker}].");
    }
}