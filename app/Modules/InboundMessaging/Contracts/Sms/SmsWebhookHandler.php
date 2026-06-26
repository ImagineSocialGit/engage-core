<?php

namespace App\Modules\InboundMessaging\Contracts\Sms;

use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

interface SmsWebhookHandler
{
    public function provider(): string;

    public function isValid(Request $request): bool;

    public function payloadFrom(Request $request): SmsWebhookPayload;

    public function response(?string $message = null): Response;
}