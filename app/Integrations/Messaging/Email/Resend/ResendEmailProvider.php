<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Contracts\Messaging\Email\EmailMessage;
use App\Contracts\Messaging\Email\EmailProvider;
use Illuminate\Support\Facades\Mail;

class ResendEmailProvider implements EmailProvider
{
    public function send(EmailMessage $message): void
    {
        Mail::to($message->to())->send($message->mailable());
    }
}