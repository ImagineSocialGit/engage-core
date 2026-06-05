<?php

namespace App\Contracts\Messaging\Email;

use App\Services\Messaging\Email\EmailWebhookPayload;

interface EmailWebhookHandler
{
    public function handle(EmailWebhookPayload $payload): void;
}