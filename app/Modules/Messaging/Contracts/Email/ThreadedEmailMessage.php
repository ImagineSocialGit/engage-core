<?php

namespace App\Modules\Messaging\Contracts\Email;

interface ThreadedEmailMessage
{
    public function inReplyTo(): ?string;

    public function references(): ?string;
}