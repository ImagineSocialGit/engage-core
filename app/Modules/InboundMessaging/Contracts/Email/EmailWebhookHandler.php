<?php

namespace App\Modules\InboundMessaging\Contracts\Email;

use App\Modules\InboundMessaging\Services\Email\EmailWebhookPayload;

interface EmailWebhookHandler
{
    public function handle(EmailWebhookPayload $payload): void;
}