<?php

namespace App\Mail;

use App\Data\WebinarMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WebinarPostFollowUpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WebinarMessageData $data,
        public string $followUpType,
        public string $subjectLine,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.webinars.post-follow-up');
    }
}