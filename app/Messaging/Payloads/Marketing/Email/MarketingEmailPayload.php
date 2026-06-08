<?php

namespace App\Messaging\Payloads\Marketing\Email;

use App\Contracts\Messaging\Email\EmailMessage;
use InvalidArgumentException;

class MarketingEmailPayload implements EmailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public string $messageType,
        public ?string $sourceIp = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        $to = $payload['to'] ?? $payload['email'] ?? $payload['contact_email'] ?? null;
        $messageType = $payload['message_type'] ?? null;
        $subject = $payload['subject'] ?? null;
        $body = $payload['body'] ?? $payload['message'] ?? null;

        if (! is_string($to) || trim($to) === '') {
            throw new InvalidArgumentException('Marketing email payload requires a destination email address.');
        }

        if (! is_string($messageType) || trim($messageType) === '') {
            throw new InvalidArgumentException('Marketing email payload requires a message_type.');
        }

        if (! is_string($subject) || trim($subject) === '') {
            throw new InvalidArgumentException("Marketing email subject is not configured for [{$messageType}].");
        }

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException("Marketing email body is not configured for [{$messageType}].");
        }

        return new self(
            to: $to,
            subject: trim($subject),
            body: trim($body),
            messageType: $messageType,
            sourceIp: $payload['source_ip'] ?? $payload['request_ip'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function html(): string
    {
        return nl2br(e($this->body));
    }

    public function text(): string
    {
        return $this->body;
    }

    public function kind(): string
    {
        return $this->messageType;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to(),
            'kind' => $this->kind(),
            'subject' => $this->subject(),
            'text' => $this->text(),
            'source_ip' => $this->sourceIp(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }
}