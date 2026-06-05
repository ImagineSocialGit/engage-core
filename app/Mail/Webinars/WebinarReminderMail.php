<?php

namespace App\Mail\Webinars;

use App\Data\WebinarMessageData;
use App\Support\Clients\ViewResolver;
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
            ->subject($this->configuredSubjectLine())
            ->view(ViewResolver::resolve('emails.webinars.reminder'));
    }

    private function configuredSubjectLine(): string
    {
        $configured = config(
            "messaging.emails.webinars.reminders.messages.{$this->messageType}.subject"
        );

        return $this->replaceTokens($configured ?: $this->subjectLine);
    }

    private function replaceTokens(string $value): string
    {
        return strtr($value, [
            ':webinar_title' => $this->data->webinarTitle,
            ':first_name' => $this->data->contactFirstName,
        ]);
    }
}