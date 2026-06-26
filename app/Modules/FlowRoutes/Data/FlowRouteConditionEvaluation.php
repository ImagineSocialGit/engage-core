<?php

namespace App\Modules\FlowRoutes\Data;

class FlowRouteConditionEvaluation
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly bool $passed,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public static function passed(?string $reason = null, array $meta = []): self
    {
        return new self(
            passed: true,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failed(?string $reason = null, array $meta = []): self
    {
        return new self(
            passed: false,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'passed' => $this->passed,
            'reason' => $this->reason,
            'meta' => $this->meta,
        ];
    }
}