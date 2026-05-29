<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\EmailMessagePayload;
use App\Data\WebinarMessageData;
use App\Mail\Webinars\WebinarRegistrationConfirmationMail;
use App\Support\Messaging\EmailConsentRevocationLinkGenerator;
use Illuminate\Mail\Mailable;

class WebinarConfirmationEmailPayload implements EmailMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
        public ?string $transactionalOptOutUrl = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
            transactionalOptOutUrl: $payload['transactional_opt_out_url'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->data->leadEmail;
    }

    public function mailable(): Mailable
    {
        return new WebinarRegistrationConfirmationMail(
            data: $this->data,
            transactionalOptOutUrl: $this->resolveTransactionalOptOutUrl(),
        );
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => 'registration_confirmation',
            'transactional_opt_out_url' => $this->resolveTransactionalOptOutUrl(),
        ];
    }

    private function resolveTransactionalOptOutUrl(): string
    {
        return $this->transactionalOptOutUrl
            ?? app(EmailConsentRevocationLinkGenerator::class)->transactionalOptOutUrl($this->data->lead());
    }
}