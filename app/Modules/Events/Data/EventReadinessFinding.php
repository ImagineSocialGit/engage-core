<?php

namespace App\Modules\Events\Data;

final readonly class EventReadinessFinding
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
    ) {}
}