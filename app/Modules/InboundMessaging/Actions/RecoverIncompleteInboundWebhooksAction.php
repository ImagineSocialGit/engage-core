<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Integrations\Messaging\Email\Resend\ResendWebhookHandler;
use App\Modules\InboundMessaging\Actions\Sms\HandleInboundSmsWebhookAction;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Replays only previously verified, durably retained inbound provider callbacks.
 * The webhook inbox itself owns the processing lease and completion fence.
 */
final class RecoverIncompleteInboundWebhooksAction
{
    public function __construct(
        private readonly WebhookInbox $inbox,
        private readonly ResendWebhookHandler $resend,
        private readonly SmsWebhookHandlerResolver $smsHandlers,
        private readonly HandleInboundSmsWebhookAction $sms,
    ) {}

    public function handle(): int
    {
        if (! (bool) config('webhooks.inbox.recovery.enabled', true)) {
            return 0;
        }

        $limit = max(1, min(100, (int) config('webhooks.inbox.recovery.batch_size', 25)));
        $maxAttempts = max(1, (int) config('webhooks.inbox.recovery.max_attempts', 8));
        $minimumAge = max(60, (int) config('webhooks.inbox.recovery.minimum_age_seconds', 600));
        $retryDelay = max(60, (int) config('webhooks.inbox.recovery.retry_delay_seconds', 300));
        $now = now();
        $inboundTelnyxEvents = array_values(array_filter(
            (array) config('sms.providers.telnyx.webhooks.inbound_event_types', []),
            static fn (mixed $type): bool => is_string($type) && trim($type) !== '',
        ));

        $receipts = WebhookInboxReceipt::query()
            ->where('client_key', config('client.key'))
            ->where('created_at', '<=', $now->copy()->subSeconds($minimumAge))
            ->where('attempts', '<', $maxAttempts)
            ->where(function (Builder $query) use ($inboundTelnyxEvents): void {
                $query->where(function (Builder $resend): void {
                    $resend->where('provider', MessageSuppression::PROVIDER_RESEND)
                        ->where('event_type', 'email.received');
                });

                if ($inboundTelnyxEvents !== []) {
                    $query->orWhere(function (Builder $telnyx) use ($inboundTelnyxEvents): void {
                        $telnyx->where('provider', MessageSuppression::PROVIDER_TELNYX)
                            ->whereIn('event_type', $inboundTelnyxEvents);
                    });
                }
            })
            ->where(function (Builder $query) use ($now, $retryDelay): void {
                $query->where('status', WebhookInboxReceipt::STATUS_RECEIVED)
                    ->orWhere(function (Builder $failed) use ($now, $retryDelay): void {
                        $failed->where('status', WebhookInboxReceipt::STATUS_RETRYABLE_FAILED)
                            ->where('last_attempted_at', '<=', $now->copy()->subSeconds($retryDelay));
                    })
                    ->orWhere(function (Builder $expired) use ($now): void {
                        $expired->where('status', WebhookInboxReceipt::STATUS_PROCESSING)
                            ->where('claim_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $completed = 0;

        foreach ($receipts as $receipt) {
            try {
                $result = $this->inbox->recoverStoredReceipt(
                    receiptId: (int) $receipt->getKey(),
                    eligible: fn (WebhookInboxReceipt $current): bool => $this->isStillEligible(
                        $current,
                        $receipt,
                        $now,
                        $minimumAge,
                        $retryDelay,
                        $maxAttempts,
                    ),
                    processor: fn (WebhookInboxReceipt $claimed): array => $this->replay($claimed),
                );

                if ($result?->status === WebhookInboxReceipt::STATUS_COMPLETED) {
                    $completed++;
                }
            } catch (Throwable $exception) {
                Log::warning('Inbound webhook receipt could not be recovered.', [
                    'receipt_id' => $receipt->getKey(),
                    'provider' => $receipt->provider,
                    'event_type' => $receipt->event_type,
                    'attempts' => $receipt->attempts,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $completed;
    }

    /**
     * Recheck the scan's tenant, identity, age, cooldown, and attempt limits
     * under WebhookInbox's row lock; another reconciler may have won the race.
     */
    private function isStillEligible(
        WebhookInboxReceipt $current,
        WebhookInboxReceipt $selected,
        \Illuminate\Support\Carbon $now,
        int $minimumAge,
        int $retryDelay,
        int $maxAttempts,
    ): bool {
        if ($current->getKey() !== $selected->getKey()
            || $current->receipt_key !== $selected->receipt_key
            || $current->client_key !== config('client.key')
            || $current->provider !== $selected->provider
            || $current->event_type !== $selected->event_type
            || (int) $current->attempts >= $maxAttempts
            || $current->created_at === null
            || $current->created_at->gt($now->copy()->subSeconds($minimumAge))
        ) {
            return false;
        }

        if ($current->status === WebhookInboxReceipt::STATUS_RECEIVED) {
            return true;
        }

        if ($current->status === WebhookInboxReceipt::STATUS_RETRYABLE_FAILED) {
            return $current->last_attempted_at !== null
                && $current->last_attempted_at->lte($now->copy()->subSeconds($retryDelay));
        }

        return $current->status === WebhookInboxReceipt::STATUS_PROCESSING
            && $current->claim_expires_at !== null
            && $current->claim_expires_at->lte($now);
    }

    /** @return array<string, mixed> */
    private function replay(WebhookInboxReceipt $receipt): array
    {
        if (! is_array($receipt->payload)) {
            throw new RuntimeException('Durable webhook receipt is missing its original payload.');
        }

        if ($receipt->provider === MessageSuppression::PROVIDER_RESEND
            && $receipt->event_type === 'email.received'
        ) {
            if (data_get($receipt->payload, 'type') !== $receipt->event_type) {
                throw new RuntimeException('Stored inbound email event type does not match its receipt.');
            }

            return $this->resend->processVerifiedPayload(
                $receipt->payload,
                $receipt->provider_event_id,
            );
        }

        if ($receipt->provider === MessageSuppression::PROVIDER_TELNYX) {
            // The original HTTP request was verified before its receipt was stored.
            // Rebuild the normalized payload without replaying provider authentication.
            $request = Request::create('/_inbound-webhook-recovery', 'POST', $receipt->payload);
            $payload = $this->smsHandlers->resolve($receipt->provider)->payloadFrom($request);
            $identity = $payload->providerEventId ?? $payload->providerMessageId;

            if (! $payload->isInboundMessage
                || $payload->provider !== $receipt->provider
                || $identity !== $receipt->provider_event_id
                || $payload->eventType !== $receipt->event_type
            ) {
                throw new RuntimeException('Stored inbound SMS payload does not match its receipt.');
            }

            $responseMessage = $this->sms->handle($payload);

            return $responseMessage !== null
                ? ['response_message' => $responseMessage]
                : [];
        }

        throw new RuntimeException('The stored provider callback is not an inbound recovery candidate.');
    }
}