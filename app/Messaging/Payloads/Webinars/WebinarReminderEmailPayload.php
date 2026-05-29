<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\EmailMessagePayload;
use App\Data\WebinarMessageData;
use App\Mail\Webinars\WebinarReminderMail;
use App\Support\Messaging\EmailConsentRevocationLinkGenerator;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;

class WebinarReminderEmailPayload implements EmailMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
        public string $messageType,
        public ?string $transactionalOptOutUrl = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
            messageType: $payload['message_type'],
            transactionalOptOutUrl: $payload['transactional_opt_out_url'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->data->leadEmail;
    }

    public function mailable(): Mailable
    {
        return new WebinarReminderMail(
            data: $this->data,
            messageType: $this->messageType,
            subjectLine: $this->subject(),
            transactionalOptOutUrl: $this->resolveTransactionalOptOutUrl(),
        );
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => 'reminder',
            'message_type' => $this->messageType,
            'subject' => $this->subject(),
            'transactional_opt_out_url' => $this->resolveTransactionalOptOutUrl(),
        ];
    }

    private function subject(): string
    {
        return match ($this->messageType) {
            'reminder_10d' => '10 days until '.$this->data->webinarTitle,
            'reminder_7d' => '1 week until '.$this->data->webinarTitle,
            'reminder_24h' => 'Tomorrow: '.$this->data->webinarTitle,
            'reminder_30m' => 'Starting soon: '.$this->data->webinarTitle,
            'reminder_10m' => 'Starts in 10 minutes: '.$this->data->webinarTitle,
            'late_joiner_5m' => 'We are live: '.$this->data->webinarTitle,
            default => throw new InvalidArgumentException("Unsupported webinar reminder type [{$this->messageType}]."),
        };
    }

    private function resolveTransactionalOptOutUrl(): string
    {
        return $this->transactionalOptOutUrl
            ?? app(EmailConsentRevocationLinkGenerator::class)->transactionalOptOutUrl($this->data->lead());
    }
}