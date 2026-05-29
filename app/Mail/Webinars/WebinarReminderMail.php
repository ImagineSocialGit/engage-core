<?php

namespace App\Mail\Webinars;

use App\Data\WebinarMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WebinarReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WebinarMessageData $data,
        public string $messageType,
        public string $subjectLine,
        public string $transactionalOptOutUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.webinars.reminder');
    }
}