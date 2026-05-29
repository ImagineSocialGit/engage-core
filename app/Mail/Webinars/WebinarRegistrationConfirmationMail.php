<?php

namespace App\Mail\Webinars;

use App\Data\WebinarMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WebinarRegistrationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WebinarMessageData $data,
        public string $transactionalOptOutUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('You’re registered: '.$this->data->webinarTitle)
            ->view('emails.webinars.registration-confirmation');
    }
}