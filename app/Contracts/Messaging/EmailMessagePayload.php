<?php

namespace App\Contracts\Messaging;

use Illuminate\Mail\Mailable;

interface EmailMessagePayload
{
    public static function fromArray(array $payload): self;

    public function to(): string;

    public function mailable(): Mailable;

    public function devPayload(): array;
}