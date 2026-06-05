<?php

namespace App\Mail\Webinars;

use App\Data\WebinarMessageData;
use App\Support\Clients\ViewResolver;
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
        public string $transactionalOptOutUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->configuredSubjectLine())
            ->view(ViewResolver::resolve('emails.webinars.post-follow-up'));
    }

    private function configuredSubjectLine(): string
    {
        $configured = config(
            "messaging.emails.webinars.post_follow_up.{$this->followUpType}.subject"
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