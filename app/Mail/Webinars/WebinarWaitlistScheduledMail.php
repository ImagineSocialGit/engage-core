<?php

namespace App\Mail\Webinars;

use Illuminate\Mail\Mailable;

class WebinarWaitlistScheduledMail extends Mailable
{
    public function __construct(
        public readonly string $webinarTitle,
        public readonly string $registrationUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('New webinar scheduled: '.$this->webinarTitle)
            ->view('emails.webinars.waitlist-scheduled', [
                'webinarTitle' => $this->webinarTitle,
                'registrationUrl' => $this->registrationUrl,
            ]);
    }
}