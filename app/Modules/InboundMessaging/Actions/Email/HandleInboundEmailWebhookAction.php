<?php

namespace App\Modules\InboundMessaging\Actions\Email;

use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\MessageSuppressionService;

class HandleInboundEmailWebhookAction
{
    private const SUPPRESSION_EVENTS = [
        'email.bounced',
        'email.complained',
        'email.suppressed',
    ];

    public function __construct(
        private readonly MessageSuppressionService $messageSuppressionService,
        private readonly RevokeMessageConsentAction $revokeMessageConsentAction,
    ) {}

    public function handle(
        array $event,
        ?string $sourceEventId = null,
        string $provider = 'resend',
    ): void {
        $type = $this->stringValue($event['type'] ?? null);

        if ($type === null) {
            return;
        }

        if (in_array($type, self::SUPPRESSION_EVENTS, true)) {
            $this->suppressContacts($event, $type, $sourceEventId, $provider);

            return;
        }

        if ($type === 'email.failed') {
            $this->handleFailedEvent($event, $sourceEventId, $provider);

            return;
        }

        if ($type === 'email.unsubscribed') {
            $this->revokeMarketingConsent($event, $sourceEventId, $provider);
        }
    }

    private function suppressContacts(
        array $event,
        string $type,
        ?string $sourceEventId,
        string $provider,
    ): void {
        $reason = match ($type) {
            'email.bounced' => MessageSuppression::REASON_BOUNCE,
            'email.complained' => MessageSuppression::REASON_COMPLAINT,
            default => MessageSuppression::REASON_PROVIDER,
        };

        foreach ($this->contactEmails($event) as $email) {
            $this->messageSuppressionService->suppress(
                channel: MessageChannel::Email,
                destination: $email,
                reason: $reason,
                provider: $provider,
                sourceEventId: $sourceEventId ?? $this->fallbackSourceEventId($event),
                meta: $this->meta($event, $provider),
            );
        }
    }

    private function handleFailedEvent(
        array $event,
        ?string $sourceEventId,
        string $provider,
    ): void {
        $failureText = strtolower(json_encode($event['data'] ?? [], JSON_THROW_ON_ERROR));

        $reason = match (true) {
            str_contains($failureText, 'invalid') => MessageSuppression::REASON_INVALID_DESTINATION,
            str_contains($failureText, 'does not exist') => MessageSuppression::REASON_INVALID_DESTINATION,
            str_contains($failureText, 'not found') => MessageSuppression::REASON_INVALID_DESTINATION,
            str_contains($failureText, 'no such user') => MessageSuppression::REASON_INVALID_DESTINATION,
            str_contains($failureText, 'mailbox unavailable') => MessageSuppression::REASON_INVALID_DESTINATION,
            str_contains($failureText, 'suppressed') => MessageSuppression::REASON_PROVIDER,
            str_contains($failureText, 'blocked') => MessageSuppression::REASON_PROVIDER,
            str_contains($failureText, 'rejected') => MessageSuppression::REASON_PROVIDER,
            default => null,
        };

        if ($reason === null) {
            return;
        }

        foreach ($this->contactEmails($event) as $email) {
            $this->messageSuppressionService->suppress(
                channel: MessageChannel::Email,
                destination: $email,
                reason: $reason,
                provider: $provider,
                sourceEventId: $sourceEventId ?? $this->fallbackSourceEventId($event),
                meta: $this->meta($event, $provider),
            );
        }
    }

    private function revokeMarketingConsent(
        array $event,
        ?string $sourceEventId,
        string $provider,
    ): void {
        foreach ($this->contactEmails($event) as $email) {
            $contact = $this->contactForEmail($email);

            if (! $contact) {
                continue;
            }

            $this->revokeMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar',
                'reason' => ConsentRevocation::REASON_PROVIDER_UNSUBSCRIBE,
                'source' => "{$provider}_webhook",
                'meta' => [
                    ...$this->meta($event, $provider),
                    'source_event_id' => $sourceEventId ?? $this->fallbackSourceEventId($event),
                ],
            ]);
        }
    }

    private function contactEmails(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return collect([
            ...$this->emailList($data['to'] ?? null),
            ...$this->emailList($data['email'] ?? null),
            ...$this->emailList($data['contact'] ?? null),
            ...$this->emailList($data['contacts'] ?? null),
        ])
            ->map(fn (string $email): string => strtolower($email))
            ->unique()
            ->values()
            ->all();
    }

    private function emailList(mixed $value): array
    {
        if (is_string($value)) {
            $email = $this->normalizeEmail($value);

            return $email ? [$email] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn (mixed $item): array => $this->emailList($item))
            ->values()
            ->all();
    }

    private function normalizeEmail(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = trim($matches[1]);
        }

        $value = strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function contactForEmail(string $email): ?Contact
    {
        return Contact::query()
            ->where('email', strtolower($email))
            ->first();
    }

    private function fallbackSourceEventId(array $event): ?string
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return $this->stringValue($data['email_id'] ?? null)
            ?? $this->stringValue($data['id'] ?? null);
    }

    private function meta(array $event, string $provider): array
    {
        return [
            'provider' => $provider,
            'event_type' => $this->stringValue($event['type'] ?? null),
            'created_at' => $this->stringValue($event['created_at'] ?? null),
            'data' => $event['data'] ?? null,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}