<?php

namespace App\Modules\Messaging\Contracts\Email;

use App\Modules\Messaging\Data\Delivery\MessageSendResult;

interface EmailProvider
{
    public function provider(): string;

    public function send(
        EmailMessage $message,
        ?string $idempotencyKey = null,
    ): MessageSendResult;
}