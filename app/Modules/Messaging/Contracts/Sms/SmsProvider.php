<?php

namespace App\Modules\Messaging\Contracts\Sms;

interface SmsProvider
{
    public function provider(): string;

    public function send(
        string $to,
        string $message,
        array $meta = [],
    ): void;
}