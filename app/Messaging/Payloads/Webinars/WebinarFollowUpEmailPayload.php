<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\EmailMessagePayload;
use App\Data\WebinarMessageData;
use App\Mail\Webinars\WebinarPostFollowUpMail;
use App\Support\Messaging\EmailConsentRevocationLinkGenerator;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;

class WebinarFollowUpEmailPayload implements EmailMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
        public string $followUpType,
        public ?string $transactionalOptOutUrl = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
            followUpType: $payload['follow_up_type'],
            transactionalOptOutUrl: $payload['transactional_opt_out_url'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->data->leadEmail;
    }

    public function mailable(): Mailable
    {
        return new WebinarPostFollowUpMail(
            data: $this->data,
            followUpType: $this->followUpType,
            subjectLine: $this->subject(),
            transactionalOptOutUrl: $this->resolveTransactionalOptOutUrl(),
        );
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => 'post_webinar_follow_up',
            'follow_up_type' => $this->followUpType,
            'subject' => $this->subject(),
            'transactional_opt_out_url' => $this->resolveTransactionalOptOutUrl(),
        ];
    }

    private function subject(): string
    {
        return match ($this->followUpType) {
            'missed' => 'Sorry we missed you: '.$this->data->webinarTitle,
            'replay' => 'Thanks for joining: '.$this->data->webinarTitle,
            default => throw new InvalidArgumentException("Unsupported webinar follow-up type [{$this->followUpType}]."),
        };
    }

    private function resolveTransactionalOptOutUrl(): string
    {
        return $this->transactionalOptOutUrl
            ?? app(EmailConsentRevocationLinkGenerator::class)->transactionalOptOutUrl($this->data->lead());
    }
}