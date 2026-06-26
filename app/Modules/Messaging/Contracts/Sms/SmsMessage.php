<?php

namespace App\Modules\Messaging\Contracts\Sms;

interface SmsMessage
{
    public static function fromArray(array $payload): self;

    public function to(): string;

    public function message(): string;

    public function kind(): string;

    public function purpose(): string;

    public function devPayload(): array;

    public function sourceIp(): ?string;
}