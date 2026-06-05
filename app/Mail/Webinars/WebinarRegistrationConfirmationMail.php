<?php

namespace App\Mail\Webinars;

use App\Data\WebinarMessageData;
use App\Support\Clients\ViewResolver;
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
            ->subject($this->subjectLine())
            ->view(ViewResolver::resolve('emails.webinars.registration-confirmation'));
    }

    private function subjectLine(): string
    {
        return $this->replaceTokens(
            config(
                'messaging.emails.webinars.registration_confirmation.subject',
                'You’re registered: :webinar_title',
            )
        );
    }

    private function replaceTokens(string $value): string
    {
        return strtr($value, [
            ':webinar_title' => $this->data->webinarTitle,
            ':first_name' => $this->data->contactFirstName,
        ]);
    }
}