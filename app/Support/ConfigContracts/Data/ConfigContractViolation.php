<?php

namespace App\Support\ConfigContracts\Data;

class ConfigContractViolation
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly string $message,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{code: string, path: string, message: string, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
            'meta' => $this->meta,
        ];
    }
}
