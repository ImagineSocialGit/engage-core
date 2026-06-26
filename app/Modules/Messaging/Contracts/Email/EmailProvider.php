<?php

namespace App\Modules\Messaging\Contracts\Email;

interface EmailProvider
{
    public function send(EmailMessage $message): void;
}