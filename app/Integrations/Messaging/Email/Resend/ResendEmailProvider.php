<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Email\EmailProvider;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use Illuminate\Support\Facades\Mail;

class ResendEmailProvider implements EmailProvider
{
    public function provider(): string
    {
        return 'resend';
    }

    public function send(EmailMessage $message): MessageSendResult
    {
        Mail::to($message->to())->send($message->mailable());

        return MessageSendResult::sent(provider: $this->provider());
    }
}
