<?php

namespace App\Support\AutomationCapabilities\Data;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class AutomationActionContext
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, Model> $models
     * @param array<string, mixed> $runtimeContext
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly array $input,
        public readonly ?Model $subject = null,
        public readonly array $models = [],
        public readonly ?Model $source = null,
        public readonly ?Model $behaviorOwner = null,
        public readonly ?string $executionKey = null,
        public readonly ?string $surface = null,
        public readonly array $runtimeContext = [],
        public readonly array $meta = [],
        public readonly ?DateTimeInterface $occurredAt = null,
    ) {}

    public function model(string $key): ?Model
    {
        $model = $this->models[$key] ?? null;

        return $model instanceof Model ? $model : null;
    }
}
