<?php

namespace App\Modules\Messaging\Contracts\Sms;

use App\Modules\Messaging\Data\Delivery\MessageSendResult;

interface SmsProvider
{
    public function provider(): string;

    public function send(
        string $to,
        string $message,
        array $meta = [],
    ): MessageSendResult;
}
