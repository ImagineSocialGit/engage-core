<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\MessageSuppressionService;

final class HandleEmailProviderEventAction
{
    public function __construct(
        private readonly MessageSuppressionService $suppressions,
        private readonly RevokeMessageConsentAction $revokeMessageConsent,
    ) {}

    /**
     * Apply durable Messaging consequences for provider-originated email events.
     *
     * Raw provider payload retention belongs to WebhookInbox. This action keeps
     * only normalized evidence needed by Messaging-owned business records.
     *
     * @param array<string, mixed> $event
     */
    public function handle(
        array $event,
        ?string $sourceEventId = null,
        string $provider = MessageSuppression::PROVIDER_RESEND,
    ): void {
        $eventType = $this->eventType($event);

        if ($eventType === null) {
            return;
        }

        if ($eventType === 'email.unsubscribed'
            || ($eventType === 'contact.updated' && data_get($event, 'data.unsubscribed') === true)
        ) {
            $this->revokeMarketingEmailPermission(
                event: $event,
                sourceEventId: $sourceEventId,
                provider: $provider,
            );

            return;
        }

        $reason = match ($eventType) {
            'email.bounced' => MessageSuppression::REASON_BOUNCE,
            'email.complained' => MessageSuppression::REASON_COMPLAINT,
            'email.suppressed' => MessageSuppression::REASON_PROVIDER,
            'email.failed' => $this->failedSuppressionReason($event),
            default => null,
        };

        if ($reason === null) {
            return;
        }

        foreach ($this->destinationEmails($event) as $email) {
            $this->suppressions->suppress(
                channel: MessageChannel::Email,
                destination: $email,
                reason: $reason,
                provider: $provider,
                sourceEventId: $sourceEventId,
                meta: $this->suppressionMeta($event, $eventType),
            );
        }
    }

    /** @param array<string, mixed> $event */
    private function failedSuppressionReason(array $event): ?string
    {
        $reason = $this->failureText($event);

        if ($reason === '') {
            return null;
        }

        foreach ([
            'temporary',
            'temporarily',
            'deferred',
            'deferral',
            'timeout',
            'timed out',
            'rate limit',
            'rate-limit',
            'try again',
            'throttl',
        ] as $temporarySignal) {
            if (str_contains($reason, $temporarySignal)) {
                return null;
            }
        }

        foreach ([
            'invalid',
            'does not exist',
            'doesn\'t exist',
            'not exist',
            'unknown user',
            'unknown recipient',
            'no such user',
            'user unknown',
            'recipient address rejected',
        ] as $invalidSignal) {
            if (str_contains($reason, $invalidSignal)) {
                return MessageSuppression::REASON_INVALID_DESTINATION;
            }
        }

        foreach ([
            'suppressed',
            'blacklist',
            'blacklisted',
            'blocked by provider',
            'provider blocked',
            'provider rejected',
        ] as $providerSignal) {
            if (str_contains($reason, $providerSignal)) {
                return MessageSuppression::REASON_PROVIDER;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<int, string>
     */
    private function destinationEmails(array $event): array
    {
        $values = [];
        $to = data_get($event, 'data.to');

        if (is_array($to)) {
            $values = [...$values, ...$to];
        } elseif ($to !== null) {
            $values[] = $to;
        }

        foreach (['data.email', 'data.recipient'] as $path) {
            $value = data_get($event, $path);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        $emails = [];

        foreach ($values as $value) {
            $email = $this->normalizeEmail($value);

            if ($email !== null) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        $value = mb_strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? $value
            : null;
    }

    /** @param array<string, mixed> $event */
    private function revokeMarketingEmailPermission(
        array $event,
        ?string $sourceEventId,
        string $provider,
    ): void {
        foreach ($this->destinationEmails($event) as $email) {
            $contact = Contact::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if (! $contact instanceof Contact) {
                continue;
            }

            $this->revokeMessageConsent->handle($contact, [
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'channel_purpose',
                'reason' => ConsentRevocation::REASON_PROVIDER_UNSUBSCRIBE,
                'source' => $provider.'_webhook',
                'meta' => array_filter([
                    'revocation_scope' => 'all_marketing_email_domains',
                    'provider' => $provider,
                    'source_event_id' => $this->nullableString($sourceEventId),
                    'provider_message_id' => $this->providerMessageId($event),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function suppressionMeta(array $event, string $eventType): array
    {
        return array_filter([
            'event_type' => $eventType,
            'provider_message_id' => $this->providerMessageId($event),
            'failure_reason' => $eventType === 'email.failed'
                ? $this->nullableString(data_get($event, 'data.reason'))
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $event */
    private function providerMessageId(array $event): ?string
    {
        foreach (['data.email_id', 'data.id'] as $path) {
            $value = $this->nullableString(data_get($event, $path));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $event */
    private function failureText(array $event): string
    {
        $parts = [];

        foreach ([
            'data.reason',
            'data.message',
            'data.error',
            'data.error.message',
            'data.response',
            'data.last_error',
        ] as $path) {
            $value = data_get($event, $path);

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);

                continue;
            }

            if (is_array($value) && $value !== []) {
                $encoded = json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
                );

                if (is_string($encoded) && $encoded !== '') {
                    $parts[] = $encoded;
                }
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /** @param array<string, mixed> $event */
    private function eventType(array $event): ?string
    {
        return $this->nullableString($event['type'] ?? $event['event'] ?? null);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}