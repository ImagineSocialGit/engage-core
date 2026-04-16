<?php

namespace App\Mail;

use App\Data\WebinarMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WebinarRegistrationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public WebinarMessageData $data
    ) {}

    public function build(): self
    {
        return $this
            ->subject('You’re registered: ' . $this->data->webinarTitle)
            ->view('emails.webinars.registration-confirmation');
    }

}
