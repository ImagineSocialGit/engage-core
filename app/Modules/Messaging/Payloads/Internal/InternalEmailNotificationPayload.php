<?php

namespace App\Modules\Messaging\Payloads\Internal;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Stringable;

class InternalEmailNotificationPayload implements EmailMessage
{
    /**
     * @param array<int, string> $body
     * @param array<string, string> $details
     * @param array<string, mixed> $cta
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $to,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly ?string $notificationType = null,
        public readonly string $subject,
        public readonly ?string $headline = null,
        public readonly ?string $preheader = null,
        public readonly array $body = [],
        public readonly array $details = [],
        public readonly array $cta = [],
        public readonly ?string $footer = null,
        public readonly ?string $sourceIp = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: self::requiredString(
                $payload['to']
                    ?? $payload['email']
                    ?? null,
                'to',
            ),
            channel: self::nullableString($payload['channel'] ?? null) ?? 'email',
            purpose: self::nullableString($payload['purpose'] ?? null) ?? 'internal',
            scope: self::requiredString($payload['scope'] ?? null, 'scope'),
            messageType: self::requiredString($payload['message_type'] ?? null, 'message_type'),
            notificationType: self::nullableString($payload['notification_type'] ?? null),
            subject: self::requiredString($payload['subject'] ?? null, 'subject'),
            headline: self::nullableString($payload['headline'] ?? null),
            preheader: self::nullableString($payload['preheader'] ?? null),
            body: self::stringList($payload['body'] ?? []),
            details: self::stringMap($payload['details'] ?? []),
            cta: self::arrayValue($payload['cta'] ?? []),
            footer: self::nullableString($payload['footer'] ?? null),
            sourceIp: self::nullableString(
                $payload['source_ip']
                    ?? $payload['request_ip']
                    ?? null,
            ),
            meta: self::arrayValue($payload['meta'] ?? []),
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function mailable(): Mailable
    {
        return new class(
            $this->subject,
            $this->html(),
            $this->fromAddress(),
            $this->fromName(),
        ) extends Mailable {
            public function __construct(
                private readonly string $subjectLine,
                private readonly string $htmlBody,
                private readonly string $senderAddress,
                private readonly ?string $senderName,
            ) {}

            public function build(): self
            {
                return $this
                    ->from($this->senderAddress, $this->senderName)
                    ->subject($this->subjectLine)
                    ->html($this->htmlBody);
            }
        };
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'from' => [
                'address' => $this->fromAddress(),
                'name' => $this->fromName(),
            ],
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'message_type' => $this->messageType,
            'notification_type' => $this->notificationType,
            'subject' => $this->subject,
            'headline' => $this->headline,
            'preheader' => $this->preheader,
            'body' => $this->body,
            'details' => $this->details,
            'cta' => $this->cta,
            'footer' => $this->footer,
            'meta' => $this->meta,
            'source_ip' => $this->sourceIp,
        ];
    }

    private function html(): string
    {
        return View::make('email', [
            'subject' => $this->subject,
            'headline' => $this->headline ?: $this->subject,
            'preheader' => $this->preheader,
            'body' => $this->body,
            'details' => $this->details,
            'cta' => $this->cta !== [] ? $this->cta : null,
            'footer' => $this->footer ?: 'This is an internal notification from '.config('app.name').'.',
        ])->render();
    }

    private function fromAddress(): string
    {
        $address = config('messaging.internal_notifications.email.from_address');

        if (! is_string($address) || trim($address) === '') {
            throw new InvalidArgumentException('Internal notification email from address is not configured.');
        }

        return trim($address);
    }

    private function fromName(): ?string
    {
        $name = config('messaging.internal_notifications.email.from_name');

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : null;
    }

    private static function requiredString(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Internal email notification payload requires [{$key}].");
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) || $value instanceof Stringable) {
            return [trim((string) $value)];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $line): ?string => self::isStringableValue($line) && trim((string) $line) !== ''
                ? trim((string) $line)
                : null,
            $value,
        )));
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            if (! self::isStringableValue($item)) {
                continue;
            }

            $map[trim($key)] = trim((string) $item);
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function isStringableValue(mixed $value): bool
    {
        return is_scalar($value) || $value instanceof Stringable;
    }
}