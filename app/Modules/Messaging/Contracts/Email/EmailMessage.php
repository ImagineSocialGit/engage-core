<?php

namespace App\Modules\Messaging\Contracts\Email;

use Illuminate\Mail\Mailable;

interface EmailMessage
{
    public static function fromArray(array $payload): self;

    public function to(): string;

    public function mailable(): Mailable;

    public function devPayload(): array;
}