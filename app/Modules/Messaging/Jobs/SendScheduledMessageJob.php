<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryLeaseManager;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $horizon
     */
    public function __construct(
        public int $scheduledMessageId,
        public array $horizon = [],
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        $backoff = config('messaging.delivery.retry_backoff_seconds', [60, 300]);

        if (! is_array($backoff)) {
            return [60, 300];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $seconds): ?int => is_numeric($seconds) && (int) $seconds >= 0
                ? (int) $seconds
                : null,
            $backoff,
        ), static fn (?int $seconds): bool => $seconds !== null)) ?: [60, 300];
    }

    public function handle(
        ClaimScheduledMessageForSendingAction $claimScheduledMessage,
        ScheduledMessageGate $scheduledMessageGate,
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
        ContactPermissionInvitationService $permissionInvitationService,
    ): void {
        $deliveryLeaseManager = app(ScheduledMessageDeliveryLeaseManager::class);

        $scheduledMessage = $claimScheduledMessage->handle($this->scheduledMessageId);

        if (! $scheduledMessage instanceof ScheduledMessage) {
            return;
        }

        $permissionInvitation = null;

        try {
            if ($denialReason = $scheduledMessageGate->denialReason($scheduledMessage)) {
                $this->markSkipped($scheduledMessage, $deliveryLeaseManager, MessageSendResult::skipped(
                    reasonCode: 'scheduled_message_gate_denied',
                    reason: $denialReason,
                ));

                return;
            }

            $permissionInvitation = $this->claimPermissionInvitation(
                scheduledMessage: $scheduledMessage,
                permissionInvitationService: $permissionInvitationService,
            );

            if ($permissionInvitationService->isImportedContactPermissionInvitationMessage($scheduledMessage)
                && ! $permissionInvitation
            ) {
                $this->markSkipped($scheduledMessage, $deliveryLeaseManager, MessageSendResult::skipped(
                    reasonCode: 'permission_invitation_already_used',
                    reason: 'Imported contact permission invitation was already used.',
                ));

                return;
            }

            if ($permissionInvitation) {
                $this->applyPermissionInvitationPayload(
                    scheduledMessage: $scheduledMessage,
                    permissionInvitation: $permissionInvitation,
                    permissionInvitationService: $permissionInvitationService,
                );
            }

            $payload = $this->resolvePayload($scheduledMessage);

            if ($reason = $this->unresolvedTokenReason($payload)) {
                $result = MessageSendResult::skipped(
                    reasonCode: 'unresolved_message_tokens',
                    reason: $reason,
                );

                $this->markSkipped($scheduledMessage, $deliveryLeaseManager, $result);
                $this->markInvitationTerminalFailure(
                    permissionInvitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    permissionInvitationService: $permissionInvitationService,
                    reason: $reason,
                );

                return;
            }

            if (! $deliveryLeaseManager->beginProviderSubmission($scheduledMessage)) {
                return;
            }

            $result = match ($scheduledMessage->channel) {
                MessageChannel::Email->value => $this->sendEmail($payload, $emailMessagingService),
                MessageChannel::Sms->value => $this->sendSms($payload, $smsMessagingService),
                default => throw new InvalidArgumentException("Unsupported message channel [{$scheduledMessage->channel}]."),
            };

            if ($result->isSkipped()) {
                $this->markSkipped($scheduledMessage, $deliveryLeaseManager, $result);
                $this->markInvitationTerminalFailure(
                    permissionInvitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    permissionInvitationService: $permissionInvitationService,
                    reason: $result->reason ?? 'Message delivery was skipped.',
                );

                return;
            }

            if ($result->isFailed()) {
                if ($result->retryable) {
                    throw new RuntimeException($result->reason ?? 'Message provider reported a retryable failure.');
                }

                $this->markFailed(
                    scheduledMessage: $scheduledMessage,
                    deliveryLeaseManager: $deliveryLeaseManager,
                    exception: new RuntimeException($result->reason ?? 'Message provider reported a terminal failure.'),
                    result: $result,
                );
                $this->markInvitationTerminalFailure(
                    permissionInvitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    permissionInvitationService: $permissionInvitationService,
                    reason: $result->reason ?? 'Message provider reported a terminal failure.',
                );

                return;
            }

            $sent = $this->markSent(
                $scheduledMessage,
                $deliveryLeaseManager,
                $result,
            );

            if (! $sent) {
                return;
            }

            if ($permissionInvitation) {
                $permissionInvitationService->markSent($permissionInvitation, $scheduledMessage);
            }
        } catch (Throwable $exception) {
            if (! $deliveryLeaseManager->ownsActiveClaim($scheduledMessage)) {
                throw $exception;
            }

            if ($this->shouldRetry(
                scheduledMessage: $scheduledMessage,
                exception: $exception,
                deliveryLeaseManager: $deliveryLeaseManager,
            )) {
                $deliveryLeaseManager->releaseForRetry(
                    $scheduledMessage,
                    $exception,
                );

                throw $exception;
            }

            $this->markFailed(
                scheduledMessage: $scheduledMessage,
                deliveryLeaseManager: $deliveryLeaseManager,
                exception: $exception,
            );
            $this->markInvitationTerminalFailure(
                permissionInvitation: $permissionInvitation,
                scheduledMessage: $scheduledMessage,
                permissionInvitationService: $permissionInvitationService,
                reason: $exception->getMessage(),
            );

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_values(array_filter([
            'scheduled-message:'.$this->scheduledMessageId,
            isset($this->horizon['recipient_type'], $this->horizon['recipient_id'])
                ? 'recipient:'.$this->horizon['recipient_type'].':'.$this->horizon['recipient_id']
                : null,
            isset($this->horizon['channel']) ? 'channel:'.$this->horizon['channel'] : null,
            isset($this->horizon['purpose']) ? 'purpose:'.$this->horizon['purpose'] : null,
            isset($this->horizon['scope']) ? 'scope:'.$this->horizon['scope'] : null,
            isset($this->horizon['message_type']) ? 'message-type:'.$this->horizon['message_type'] : null,
            isset($this->horizon['queue']) ? 'queue:'.$this->horizon['queue'] : null,
        ]));
    }

    private function resolvePayload(ScheduledMessage $scheduledMessage): EmailMessage|SmsMessage
    {
        $payloadClass = $scheduledMessage->payload_class;

        if (! is_string($payloadClass) || ! class_exists($payloadClass)) {
            throw new InvalidArgumentException('Scheduled message payload class is invalid.');
        }

        if (! method_exists($payloadClass, 'fromArray')) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must define fromArray().");
        }

        $payloadData = array_replace_recursive(
            [
                'channel' => $scheduledMessage->channel,
                'purpose' => $scheduledMessage->purpose,
                'scope' => $scheduledMessage->scope,
                'message_type' => $scheduledMessage->message_type,
            ],
            $scheduledMessage->payload ?? [],
        );

        if (filled($scheduledMessage->provider_idempotency_key)) {
            $payloadData['meta'] = array_replace_recursive(
                is_array($payloadData['meta'] ?? null) ? $payloadData['meta'] : [],
                [
                    'delivery' => [
                        'provider_idempotency_key' => $scheduledMessage->provider_idempotency_key,
                    ],
                ],
            );
        }

        $payload = $payloadClass::fromArray($payloadData);

        if (! $payload instanceof EmailMessage && ! $payload instanceof SmsMessage) {
            throw new InvalidArgumentException("Payload class [{$payloadClass}] must implement a supported message payload contract.");
        }

        return $payload;
    }

    private function unresolvedTokenReason(EmailMessage|SmsMessage $payload): ?string
    {
        if (! method_exists($payload, 'devPayload')) {
            return null;
        }

        $devPayload = $payload->devPayload();
        unset($devPayload['tokens']);

        $tokens = $this->unresolvedTokens(
            value: $devPayload,
            ignoredTokens: $this->structuredRenderSlotTokens($devPayload),
        );

        if ($tokens === []) {
            return null;
        }

        return 'Message payload contains unresolved token(s): '.implode(', ', $tokens).'.';
    }

    /**
     * @return array<int, string>
     */
    private function unresolvedTokens(mixed $value, array $ignoredTokens = []): array
    {
        $tokens = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                $tokens = array_merge($tokens, $this->unresolvedTokens($item, $ignoredTokens));
            }

            return array_values(array_unique($tokens));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_.:-]*\}/', $value, $matches);

        foreach ($matches[0] ?? [] as $token) {
            if (in_array($token, $ignoredTokens, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function structuredRenderSlotTokens(array $payload): array
    {
        $tokens = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }

            if ($this->isValidStructuredLink($value)) {
                $tokens[] = '{'.$key.'}';

                continue;
            }

            if ($key === 'ctas' && array_is_list($value)) {
                $hasValidCta = collect($value)
                    ->contains(fn (mixed $cta): bool => is_array($cta) && $this->isValidStructuredLink($cta));

                if ($hasValidCta) {
                    $tokens[] = '{cta}';
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function isValidStructuredLink(array $value): bool
    {
        return is_string($value['label'] ?? null)
            && trim($value['label']) !== ''
            && is_string($value['url'] ?? null)
            && trim($value['url']) !== '';
    }

    private function sendEmail(
        EmailMessage|SmsMessage $payload,
        EmailMessagingService $emailMessagingService,
    ): MessageSendResult {
        if (! $payload instanceof EmailMessage) {
            throw new InvalidArgumentException('Scheduled email message resolved to a non-email payload.');
        }

        return $emailMessagingService->send($payload);
    }

    private function sendSms(
        EmailMessage|SmsMessage $payload,
        SmsMessagingService $smsMessagingService,
    ): MessageSendResult {
        if (! $payload instanceof SmsMessage) {
            throw new InvalidArgumentException('Scheduled SMS message resolved to a non-SMS payload.');
        }

        return $smsMessagingService->send($payload);
    }

    private function markSent(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
        MessageSendResult $result,
    ): bool {
        $completed = $deliveryLeaseManager->complete(
            claimedMessage: $scheduledMessage,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        ScheduledMessageSent::dispatch($completed);

        return true;
    }

    private function markSkipped(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
        MessageSendResult $result,
    ): bool {
        $completed = $deliveryLeaseManager->complete(
            claimedMessage: $scheduledMessage,
            status: ScheduledMessage::STATUS_SKIPPED,
            result: $result,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        ScheduledMessageSkipped::dispatch($completed);

        return true;
    }

    private function markFailed(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
        Throwable $exception,
        ?MessageSendResult $result = null,
    ): bool {
        $result ??= MessageSendResult::failed(
            reasonCode: 'message_delivery_exception',
            reason: $exception->getMessage(),
            retryable: false,
        );

        $completed = $deliveryLeaseManager->complete(
            claimedMessage: $scheduledMessage,
            status: ScheduledMessage::STATUS_FAILED,
            result: $result,
            exception: $exception,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        ScheduledMessageFailed::dispatch($completed);

        return true;
    }

    private function shouldRetry(
        ScheduledMessage $scheduledMessage,
        Throwable $exception,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
    ): bool {
        if ($exception instanceof InvalidArgumentException) {
            return false;
        }

        return (int) $scheduledMessage->send_attempts < $this->tries
            && $deliveryLeaseManager->canRetryAfterProviderSubmission($scheduledMessage);
    }

    private function claimPermissionInvitation(
        ScheduledMessage $scheduledMessage,
        ContactPermissionInvitationService $permissionInvitationService,
    ): ?ContactPermissionInvitation {
        return $permissionInvitationService->claimForScheduledMessage($scheduledMessage);
    }

    private function applyPermissionInvitationPayload(
        ScheduledMessage $scheduledMessage,
        ContactPermissionInvitation $permissionInvitation,
        ContactPermissionInvitationService $permissionInvitationService,
    ): void {
        $scheduledMessage->forceFill([
            'payload' => array_replace_recursive(
                $scheduledMessage->payload ?? [],
                $permissionInvitationService->publicEmailPayload($permissionInvitation),
            ),
        ])->save();
    }

    private function markInvitationTerminalFailure(
        ?ContactPermissionInvitation $permissionInvitation,
        ScheduledMessage $scheduledMessage,
        ContactPermissionInvitationService $permissionInvitationService,
        string $reason,
    ): void {
        if (! $permissionInvitation instanceof ContactPermissionInvitation) {
            return;
        }

        $currentMessage = $scheduledMessage->fresh();

        if (! $currentMessage instanceof ScheduledMessage
            || ! in_array($currentMessage->status, [
                ScheduledMessage::STATUS_SKIPPED,
                ScheduledMessage::STATUS_FAILED,
            ], true)
        ) {
            return;
        }

        $permissionInvitationService->markFailed(
            invitation: $permissionInvitation,
            scheduledMessage: $scheduledMessage,
            reason: $reason,
        );
    }
}