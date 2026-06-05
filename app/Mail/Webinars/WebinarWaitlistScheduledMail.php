<?php

namespace App\Mail\Webinars;

use App\Support\Clients\ViewResolver;
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
            ->subject($this->subjectLine())
            ->view(ViewResolver::resolve('emails.webinars.waitlist-scheduled'), [
                'webinarTitle' => $this->webinarTitle,
                'registrationUrl' => $this->registrationUrl,
            ]);
    }

    private function subjectLine(): string
    {
        return strtr(
            config(
                'messaging.emails.webinars.waitlist_scheduled.subject',
                'New webinar scheduled: :webinar_title',
            ),
            [
                ':webinar_title' => $this->webinarTitle,
            ],
        );
    }
}