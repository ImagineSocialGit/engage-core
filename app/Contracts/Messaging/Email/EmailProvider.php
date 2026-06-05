<?php

namespace App\Contracts\Messaging\Email;

interface EmailProvider
{
    public function send(EmailMessage $message): void;
}