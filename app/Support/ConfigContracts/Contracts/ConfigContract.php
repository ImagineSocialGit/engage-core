<?php

namespace App\Support\ConfigContracts\Contracts;

use App\Support\ConfigContracts\Data\ConfigSchema;

interface ConfigContract
{
    public function key(): string;

    public function owner(): string;

    public function sourcePattern(): string;

    public function schema(): ConfigSchema;

    /**
     * @return array<string, mixed>
     */
    public function example(): array;
}
