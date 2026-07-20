<?php

namespace App\Modules\Messaging\Payloads;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Support\EmailConsentRevocationLinkGenerator;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use App\Support\Clients\ViewResolver;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Stringable;

class EmailPayload implements EmailMessage
{
    private const DEFAULT_VIEW = 'email';

    public function __construct(
        public readonly string $to,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly ?int $contactId = null,
        public readonly ?string $subject = null,
        public readonly ?string $body = null,
        public readonly ?string $view = null,
        public readonly array $tokens = [],
        public readonly array $cta = [],
        public readonly array $ctas = [],
        public readonly array $secondaryLink = [],
        public readonly ?string $footer = null,
        public readonly ?string $unsubscribeUrl = null,
        public readonly ?string $transactionalOptOutUrl = null,
        public readonly ?string $sourceIp = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        $to = $payload['to']
            ?? $payload['email']
            ?? $payload['contact_email']
            ?? null;

        if (! is_string($to) || trim($to) === '') {
            throw new InvalidArgumentException('Email payload requires a destination email address.');
        }

        return new self(
            to: trim($to),
            channel: trim((string) ($payload['channel'] ?? 'email')),
            purpose: trim((string) ($payload['purpose'] ?? '')),
            scope: trim((string) ($payload['scope'] ?? '')),
            messageType: trim((string) ($payload['message_type'] ?? '')),

            contactId: self::nullableInt(
                $payload['contact_id']
                    ?? data_get($payload, 'contact.id')
                    ?? null
            ),

            subject: self::nullableString($payload['subject'] ?? null),

            body: self::nullableString(
                $payload['body']
                    ?? $payload['message']
                    ?? $payload['message_body']
                    ?? null
            ),

            view: self::nullableString($payload['view'] ?? null),

            tokens: self::resolveTokens($payload),

            cta: self::arrayValue($payload['cta'] ?? null),

            ctas: self::listArrayValue($payload['ctas'] ?? null),

            secondaryLink: self::arrayValue($payload['secondary_link'] ?? null),

            footer: self::nullableString($payload['footer'] ?? null),

            unsubscribeUrl: self::nullableString($payload['unsubscribe_url'] ?? null),

            transactionalOptOutUrl: self::nullableString($payload['transactional_opt_out_url'] ?? null),

            sourceIp: self::nullableString(
                $payload['source_ip']
                    ?? $payload['request_ip']
                    ?? null
            ),

            meta: is_array($payload['meta'] ?? null)
                ? $payload['meta']
                : [],
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function subject(): string
    {
        $subject = $this->subject
            ?? config($this->payloadConfigPath('subject'));

        if (! is_string($subject) || trim($subject) === '') {
            throw new InvalidArgumentException(
                "Email subject is not configured for [{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}]."
            );
        }

        return trim($this->interpolate($subject));
    }

    public function text(): string
    {
        $body = $this->body
            ?? config($this->payloadConfigPath('body'));

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException(
                "Email body is not configured for [{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}]."
            );
        }

        return trim($this->interpolate($body));
    }

    public function plainText(): string
    {
        $body = $this->text();
        $ctaBlock = $this->plainTextCtaBlock();

        if (str_contains($body, '{cta}')) {
            $body = str_replace('{cta}', $ctaBlock, $body);
        } elseif ($ctaBlock !== '') {
            $body = rtrim($body)."\n\n".$ctaBlock;
        }

        $sections = [trim($body)];

        $secondaryLink = $this->resolvedArray(
            'secondary_link',
            $this->secondaryLink,
        );

        if ($this->validLink($secondaryLink)) {
            $sections[] = trim((string) $secondaryLink['label'])
                .":\n"
                .trim((string) $secondaryLink['url']);
        }

        $footer = $this->footer ?? $this->configValue('footer');

        if (is_string($footer) && trim($footer) !== '') {
            $sections[] = trim($footer);
        }

        $transactionalOptOutUrl = $this->transactionalOptOutUrl();
        $marketingUnsubscribeUrl = $this->marketingUnsubscribeUrl();

        if (is_string($transactionalOptOutUrl) && trim($transactionalOptOutUrl) !== '') {
            $sections[] = "Don't want these emails?\n".trim($transactionalOptOutUrl);
        } elseif (is_string($marketingUnsubscribeUrl) && trim($marketingUnsubscribeUrl) !== '') {
            $sections[] = "Unsubscribe:\n".trim($marketingUnsubscribeUrl);
        }

        return trim(implode(
            "\n\n",
            array_values(array_filter(
                $sections,
                fn (mixed $section): bool => is_string($section) && trim($section) !== '',
            )),
        ))."\n";
    }

    public function html(): string
    {
        return View::make(
            ViewResolver::resolve($this->view()),
            [
                ...$this->tokens,

                'subject' => $this->subject(),

                'headline' => $this->interpolate(
                    (string) (
                        config($this->payloadConfigPath('headline'))
                        ?? $this->subject()
                    )
                ),

                'preheader' => $this->configValue('preheader'),

                'body' => $this->bodyLines(),

                'details' => $this->configArray('details'),

                'cta' => $this->resolvedArray('cta', $this->cta),

                'ctas' => $this->resolvedListArray('ctas', $this->ctas),

                'secondary_link' => $this->resolvedArray('secondary_link', $this->secondaryLink),

                'footer' => $this->footer ?? $this->configValue('footer'),

                'unsubscribeUrl' => $this->marketingUnsubscribeUrl(),

                'transactionalOptOutUrl' => $this->transactionalOptOutUrl(),
            ]
        )->render();
    }

    public function mailable(): Mailable
    {
        return new class(
            $this->subject(),
            $this->html(),
            $this->plainText(),
            $this->fromAddress(),
            $this->fromName(),
        ) extends Mailable {
            public function __construct(
                private readonly string $subjectLine,
                private readonly string $htmlBody,
                private readonly string $plainTextBody,
                private readonly string $senderAddress,
                private readonly ?string $senderName,
            ) {}

            public function build(): self
            {
                return $this
                    ->from($this->senderAddress, $this->senderName)
                    ->subject($this->subjectLine)
                    ->html($this->htmlBody)
                    ->text('email-text', [
                        'content' => $this->plainTextBody,
                    ]);
            }
        };
    }

    public function kind(): string
    {
        return $this->messageType;
    }

    private function fromAddress(): string
    {
        $address = $this->fromConfigValue('address');

        if (! is_string($address) || trim($address) === '') {
            throw new InvalidArgumentException(
                "Email from address is not configured for purpose [{$this->purpose}]."
            );
        }

        return trim($address);
    }

    private function fromName(): ?string
    {
        $name = $this->fromConfigValue('name');

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : null;
    }

    private function fromConfigValue(string $key): ?string
    {
        $provider = config('messaging.email.provider');

        if (is_string($provider) && trim($provider) !== '') {
            $providerValue = config("messaging.email.providers.{$provider}.from.{$this->purpose}.{$key}");

            if (is_string($providerValue) && trim($providerValue) !== '') {
                return $providerValue;
            }
        }

        $value = config("messaging.email.from.{$this->purpose}.{$key}");

        return is_string($value) && trim($value) !== ''
            ? $value
            : null;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'from' => [
                'address' => $this->fromConfigValue('address'),
                'name' => $this->fromConfigValue('name'),
            ],
            'subject' => $this->subject(),
            'text' => $this->text(),
            'view' => $this->view(),
            'cta' => $this->resolvedArray('cta', $this->cta),
            'ctas' => $this->resolvedListArray('ctas', $this->ctas),
            'secondary_link' => $this->resolvedArray('secondary_link', $this->secondaryLink),
            'footer' => $this->footer ?? $this->configValue('footer'),
            'unsubscribe_url' => $this->marketingUnsubscribeUrl(),
            'transactional_opt_out_url' => $this->transactionalOptOutUrl(),
            'tokens' => $this->tokens,
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }

    private function view(): string
    {
        return $this->view
            ?? config($this->payloadConfigPath('view'))
            ?? self::DEFAULT_VIEW;
    }

    private function bodyLines(): array
    {
        return array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $this->text()) ?: []
        ));
    }

    private function configValue(string $key): ?string
    {
        $value = config($this->payloadConfigPath($key));

        if (! is_string($value)) {
            return null;
        }

        return $this->interpolate($value);
    }

    private function configArray(string $key): array
    {
        $value = config($this->payloadConfigPath($key));

        return is_array($value)
            ? $value
            : [];
    }

    private function payloadConfigPath(string $key): string
    {
        return MessageDefinitionConfigPath::payloadField(
            channel: $this->channel,
            purpose: $this->purpose,
            scope: $this->scope,
            messageType: $this->messageType,
            field: $key,
        );
    }

    private function interpolate(string $value): string
    {
        $replacements = [];

        foreach (Arr::dot($this->tokens) as $key => $tokenValue) {
            if (! self::isStringableValue($tokenValue)) {
                continue;
            }

            $replacements["{{$key}}"] = (string) $tokenValue;
            $replacements[":{$key}"] = (string) $tokenValue;
        }

        return strtr($value, $replacements);
    }

    private function interpolateRecursive(array $values): array
    {
        array_walk_recursive($values, function (&$value) {
            if (is_string($value)) {
                $value = $this->interpolate($value);
            }
        });

        return $values;
    }

    private static function resolveTokens(array $payload): array
    {
        return array_replace_recursive(
            is_array($payload['runtime_context'] ?? null) ? $payload['runtime_context'] : [],
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
            is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
        );
    }

    private static function isStringableValue(mixed $value): bool
    {
        return is_scalar($value) || $value instanceof Stringable;
    }

    private function resolvedArray(string $key, array $value): array
    {
        return $this->interpolateRecursive(
            $value !== [] ? $value : $this->configArray($key)
        );
    }

    private function resolvedListArray(string $key, array $value): array
    {
        $resolved = $value !== [] ? $value : $this->configArray($key);

        if (! array_is_list($resolved)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $item): ?array => is_array($item)
                    ? $this->interpolateRecursive($item)
                    : null,
                $resolved,
            ),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvedCtas(): array
    {
        $resolved = $this->resolvedListArray('ctas', $this->ctas);

        if ($resolved === []) {
            $single = $this->resolvedArray('cta', $this->cta);

            if ($this->validLink($single)) {
                $resolved[] = $single;
            }
        }

        return array_values(array_filter(
            $resolved,
            fn (array $cta): bool => $this->validLink($cta),
        ));
    }

    private function plainTextCtaBlock(): string
    {
        return implode(
            "\n\n",
            array_map(
                fn (array $cta): string => trim((string) $cta['label'])
                    .":\n"
                    .trim((string) $cta['url']),
                $this->resolvedCtas(),
            ),
        );
    }

    /**
     * @param array<string, mixed> $link
     */
    private function validLink(array $link): bool
    {
        return is_string($link['label'] ?? null)
            && trim((string) $link['label']) !== ''
            && is_string($link['url'] ?? null)
            && trim((string) $link['url']) !== '';
    }

    private function marketingUnsubscribeUrl(): ?string
    {
        if ($this->unsubscribeUrl) {
            return $this->interpolate($this->unsubscribeUrl);
        }

        if ($this->purpose !== 'marketing') {
            return $this->configValue('unsubscribe_url');
        }

        $contact = $this->contact();

        return $contact
            ? app(EmailConsentRevocationLinkGenerator::class)->marketingUnsubscribeUrl($contact)
            : $this->configValue('unsubscribe_url');
    }

    private function transactionalOptOutUrl(): ?string
    {
        if ($this->transactionalOptOutUrl) {
            return $this->interpolate($this->transactionalOptOutUrl);
        }

        if ($this->purpose !== 'transactional') {
            return $this->configValue('transactional_opt_out_url');
        }

        $contact = $this->contact();

        return $contact
            ? app(EmailConsentRevocationLinkGenerator::class)->transactionalOptOutUrl($contact, $this->scope)
            : $this->configValue('transactional_opt_out_url');
    }

    private function contact(): ?Contact
    {
        return $this->contactId
            ? Contact::query()->find($this->contactId)
            : null;
    }

    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function listArrayValue(mixed $value): array
    {
        return is_array($value) && array_is_list($value)
            ? $value
            : [];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}