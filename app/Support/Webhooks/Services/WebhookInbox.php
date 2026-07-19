<?php

namespace App\Support\Webhooks\Services;

use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class WebhookInbox
{
    /**
     * @param array<string, mixed> $payload
     * @param Closure(): mixed $processor
     */
    public function process(
        string $provider,
        array $payload,
        Closure $processor,
        ?string $providerEventId = null,
        ?string $signatureFingerprint = null,
        ?string $eventType = null,
    ): WebhookInboxReceipt {
        $provider = $this->requiredString($provider, 'provider');
        $providerEventId = $this->nullableString($providerEventId);
        $signatureFingerprint = $this->nullableString($signatureFingerprint);

        if ($providerEventId === null && $signatureFingerprint === null) {
            throw new InvalidArgumentException(
                'A provider event ID or signature fingerprint is required for a durable webhook receipt.',
            );
        }

        $clientKey = $this->nullableString(config('client.key'));
        $payloadFingerprint = $this->payloadFingerprint($payload);
        $receiptKey = $this->receiptKey(
            clientKey: $clientKey,
            provider: $provider,
            providerEventId: $providerEventId,
            signatureFingerprint: $signatureFingerprint,
        );

        $receipt = WebhookInboxReceipt::query()->firstOrCreate(
            ['receipt_key' => $receiptKey],
            [
                'client_key' => $clientKey,
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'signature_fingerprint' => $signatureFingerprint,
                'event_type' => $this->nullableString($eventType),
                'payload_fingerprint' => $payloadFingerprint,
                'payload' => $payload,
                'status' => WebhookInboxReceipt::STATUS_RECEIVED,
                'attempts' => 0,
            ],
        );

        $this->assertSameRequest(
            receipt: $receipt,
            provider: $provider,
            providerEventId: $providerEventId,
            payloadFingerprint: $payloadFingerprint,
        );

        $claimed = $this->claim((int) $receipt->getKey());

        if (! $claimed instanceof WebhookInboxReceipt) {
            return $receipt->fresh() ?? $receipt;
        }

        try {
            $outcome = $processor();

            $this->markCompleted($claimed, $this->normalizeOutcome($outcome));
        } catch (Throwable $exception) {
            $this->markRetryableFailed($claimed, $exception);

            throw $exception;
        }

        return $claimed->fresh() ?? $claimed;
    }

    private function claim(int $receiptId): ?WebhookInboxReceipt
    {
        return DB::transaction(function () use ($receiptId): ?WebhookInboxReceipt {
            $receipt = WebhookInboxReceipt::query()
                ->lockForUpdate()
                ->find($receiptId);

            if (! $receipt instanceof WebhookInboxReceipt
                || $receipt->status === WebhookInboxReceipt::STATUS_COMPLETED
            ) {
                return null;
            }

            if ($receipt->status === WebhookInboxReceipt::STATUS_PROCESSING
                && $receipt->claim_expires_at?->isFuture()
            ) {
                return null;
            }

            $claimedAt = now();

            $receipt->forceFill([
                'status' => WebhookInboxReceipt::STATUS_PROCESSING,
                'attempts' => ((int) $receipt->attempts) + 1,
                'claim_token' => (string) Str::uuid(),
                'claim_expires_at' => $claimedAt->copy()->addSeconds($this->claimLeaseSeconds()),
                'last_attempted_at' => $claimedAt,
                'failed_at' => null,
                'last_error' => null,
            ])->save();

            return $receipt;
        }, 3);
    }

    /**
     * @param array<string, mixed>|null $outcome
     */
    private function markCompleted(
        WebhookInboxReceipt $claimed,
        ?array $outcome,
    ): void {
        $updated = WebhookInboxReceipt::query()
            ->whereKey($claimed->getKey())
            ->where('status', WebhookInboxReceipt::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => WebhookInboxReceipt::STATUS_COMPLETED,
                'claim_token' => null,
                'claim_expires_at' => null,
                'completed_at' => now(),
                'failed_at' => null,
                'outcome' => $outcome,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new LogicException(
                "Webhook inbox receipt [{$claimed->getKey()}] lost its processing claim.",
            );
        }
    }

    private function markRetryableFailed(
        WebhookInboxReceipt $claimed,
        Throwable $exception,
    ): void {
        WebhookInboxReceipt::query()
            ->whereKey($claimed->getKey())
            ->where('status', WebhookInboxReceipt::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
                'claim_token' => null,
                'claim_expires_at' => null,
                'failed_at' => now(),
                'outcome' => null,
                'last_error' => Str::limit($exception->getMessage(), 65000, ''),
                'updated_at' => now(),
            ]);
    }

    private function receiptKey(
        ?string $clientKey,
        string $provider,
        ?string $providerEventId,
        ?string $signatureFingerprint,
    ): string {
        $identityType = $providerEventId !== null ? 'event' : 'signature';
        $identity = $providerEventId ?? $signatureFingerprint;

        return hash('sha256', implode('|', [
            $clientKey ?? '',
            $provider,
            $identityType,
            $identity,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadFingerprint(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
    }

    private function assertSameRequest(
        WebhookInboxReceipt $receipt,
        string $provider,
        ?string $providerEventId,
        string $payloadFingerprint,
    ): void {
        if ($receipt->provider !== $provider
            || $receipt->provider_event_id !== $providerEventId
            || $receipt->payload_fingerprint !== $payloadFingerprint
        ) {
            throw new LogicException(
                "Webhook receipt identity [{$receipt->receipt_key}] was reused for a different request.",
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeOutcome(mixed $outcome): ?array
    {
        if ($outcome === null) {
            return null;
        }

        return is_array($outcome)
            ? $outcome
            : ['value' => $outcome];
    }

    private function requiredString(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("A non-empty {$name} is required.");
        }

        return strtolower($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function claimLeaseSeconds(): int
    {
        return max(30, (int) config('webhooks.inbox.claim_lease_seconds', 300));
    }
}
