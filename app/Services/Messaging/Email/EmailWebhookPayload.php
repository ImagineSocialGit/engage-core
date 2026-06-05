<?php

namespace App\Services\Messaging\Email;

use Illuminate\Http\Request;

class EmailWebhookPayload
{
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly ?string $rawBody = null,
    ) {}

    public static function fromRequest(string $provider, Request $request): self
    {
        return new self(
            provider: $provider,
            payload: $request->all(),
            headers: $request->headers->all(),
            rawBody: $request->getContent(),
        );
    }

    public function eventType(): ?string
    {
        return data_get($this->payload, 'type');
    }

    public function data(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, "data.{$key}", $default);
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return data_get($this->headers, strtolower($key), $default)
            ?? data_get($this->headers, $key, $default);
    }
}