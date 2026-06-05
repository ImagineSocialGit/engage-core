<?php

namespace App\Contracts\Messaging;

interface SmsProvider
{
    public function provider(): string;

    public function send(
        string $to,
        string $message,
        array $meta = [],
    ): void;
}