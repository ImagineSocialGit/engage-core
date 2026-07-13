<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use InvalidArgumentException;

class ConsentOptInDefinitionResolver
{
    public function __construct(
        private readonly ConsentDomainRegistry $consentDomainRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $messageScope,
    ): array {
        $channel = $this->enumValue($channel);
        $purpose = $this->enumValue($purpose);
        $domain = $this->consentDomainRegistry->domainForScope($messageScope);
        $domainDefinition = $this->consentDomainRegistry->definition($domain) ?? [];

        $defaults = config("messaging.consent.opt_in_defaults.{$channel}.{$purpose}", []);
        $override = data_get($domainDefinition, "opt_in.{$channel}.{$purpose}", []);

        if (! is_array($defaults)) {
            $defaults = [];
        }

        if (! is_array($override)) {
            $override = [];
        }

        $copy = array_replace_recursive($defaults, $override);
        $payload = $this->payload($channel, $copy, $domain);

        return [
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $domain,
            'payload_class' => $this->payloadClass($channel),
            'queue' => $this->filledString($copy['queue'] ?? null)
                ? trim($copy['queue'])
                : 'opt_in_messages',
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $copy
     * @return array<string, mixed>
     */
    private function payload(string $channel, array $copy, string $domain): array
    {
        $replacements = [
            ':client_name' => $this->clientName(),
            ':consent_topic' => $this->consentDomainRegistry->topicForDomain($domain),
        ];

        return match ($channel) {
            MessageChannel::Email->value => [
                'subject' => strtr($this->requiredString($copy, 'subject', $channel), $replacements),
                'body' => strtr($this->requiredString($copy, 'body', $channel), $replacements),
            ],
            MessageChannel::Sms->value => [
                'message' => strtr($this->requiredString($copy, 'message', $channel), $replacements),
            ],
            default => throw new InvalidArgumentException("Unsupported consent opt-in channel [{$channel}]."),
        };
    }

    private function payloadClass(string $channel): string
    {
        return match ($channel) {
            MessageChannel::Email->value => EmailPayload::class,
            MessageChannel::Sms->value => SmsPayload::class,
            default => throw new InvalidArgumentException("Unsupported consent opt-in channel [{$channel}]."),
        };
    }

    /** @param array<string, mixed> $copy */
    private function requiredString(array $copy, string $key, string $channel): string
    {
        $value = $copy[$key] ?? null;

        if (! $this->filledString($value)) {
            throw new InvalidArgumentException(
                "Consent opt-in copy for channel [{$channel}] is missing [{$key}]."
            );
        }

        return trim($value);
    }

    private function clientName(): string
    {
        $name = config('client.name', config('app.name', ''));

        return $this->filledString($name) ? trim($name) : 'this organization';
    }

    private function enumValue(MessageChannel|MessagePurpose|string $value): string
    {
        return $value instanceof MessageChannel || $value instanceof MessagePurpose
            ? $value->value
            : str_replace('-', '_', strtolower(trim($value)));
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
