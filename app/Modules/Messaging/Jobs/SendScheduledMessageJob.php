<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ProviderSubmissionLimiter;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryLeaseManager;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
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
        ProviderSubmissionLimiter $providerSubmissionLimiter,
    ): void {
        $deliveryLeaseManager = app(ScheduledMessageDeliveryLeaseManager::class);

        $deliveryAttempt = $claimScheduledMessage->handle(
            $this->scheduledMessageId,
        );

        if (! $deliveryAttempt instanceof ScheduledMessageDeliveryAttempt) {
            return;
        }

        $scheduledMessage = $deliveryAttempt->scheduledMessage;

        if (! $scheduledMessage instanceof ScheduledMessage) {
            return;
        }

        $permissionInvitation = null;

        try {
            if ($denialReason = $scheduledMessageGate->denialReason($scheduledMessage)) {
                $this->markSkipped($deliveryAttempt, $deliveryLeaseManager, MessageSendResult::skipped(
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
                $this->markSkipped($deliveryAttempt, $deliveryLeaseManager, MessageSendResult::skipped(
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

            $payload = app(ScheduledMessagePayloadResolver::class)
                ->resolve($scheduledMessage);

            if ($reason = $this->unresolvedTokenReason($payload)) {
                $result = MessageSendResult::skipped(
                    reasonCode: 'unresolved_message_tokens',
                    reason: $reason,
                );

                $this->markSkipped($deliveryAttempt, $deliveryLeaseManager, $result);
                $this->markInvitationTerminalFailure(
                    permissionInvitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    permissionInvitationService: $permissionInvitationService,
                    reason: $reason,
                );

                return;
            }

            $providerSubmissionLimiter->acquire(
                channel: $scheduledMessage->channel,
                provider: $this->providerKey($scheduledMessage),
            );

            if (! $deliveryLeaseManager->beginProviderSubmission(
                claimedAttempt: $deliveryAttempt,
                destination: $payload->to(),
            )) {
                return;
            }

            $result = match ($scheduledMessage->channel) {
                MessageChannel::Email->value => $this->sendEmail($payload, $emailMessagingService),
                MessageChannel::Sms->value => $this->sendSms($payload, $smsMessagingService),
                default => throw new InvalidArgumentException("Unsupported message channel [{$scheduledMessage->channel}]."),
            };

            if ($result->isSkipped()) {
                $this->markSkipped($deliveryAttempt, $deliveryLeaseManager, $result);
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
                    deliveryAttempt: $deliveryAttempt,
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
                $deliveryAttempt,
                $deliveryLeaseManager,
                $result,
            );

            if (! $sent) {
                return;
            }

            if ($permissionInvitation) {
                $terminalResult = $this->terminalResult($scheduledMessage);

                $permissionInvitationService->markSent(
                    invitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    sentAt: $terminalResult->occurredAt,
                );
            }
        } catch (Throwable $exception) {
            if (! $deliveryLeaseManager->ownsActiveClaim($deliveryAttempt)) {
                throw $exception;
            }

            if ($this->shouldRetry(
                deliveryAttempt: $deliveryAttempt,
                exception: $exception,
                deliveryLeaseManager: $deliveryLeaseManager,
            )) {
                $deliveryLeaseManager->releaseForRetry(
                    $deliveryAttempt,
                    $exception,
                );

                throw $exception;
            }

            $this->markFailed(
                deliveryAttempt: $deliveryAttempt,
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

        preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_.:-]*\}/', $value, $bracedMatches);
        preg_match_all(
            '/(?<![a-zA-Z0-9_]):[a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)*/',
            $value,
            $colonMatches,
        );

        foreach ([
            ...($bracedMatches[0] ?? []),
            ...($colonMatches[0] ?? []),
        ] as $token) {
            if (! is_string($token) || in_array($token, $ignoredTokens, true)) {
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


    private function providerKey(ScheduledMessage $scheduledMessage): string
    {
        return match ($scheduledMessage->channel) {
            MessageChannel::Email->value => trim((string) config('messaging.email.provider', '')),
            MessageChannel::Sms->value => trim((string) config('sms.provider', '')),
            default => '',
        };
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
        ScheduledMessageDeliveryAttempt $deliveryAttempt,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
        MessageSendResult $result,
    ): bool {
        $completed = $deliveryLeaseManager->complete(
            claimedAttempt: $deliveryAttempt,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        return true;
    }

    private function markSkipped(
        ScheduledMessageDeliveryAttempt $deliveryAttempt,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
        MessageSendResult $result,
    ): bool {
        $completed = $deliveryLeaseManager->complete(
            claimedAttempt: $deliveryAttempt,
            status: ScheduledMessage::STATUS_SKIPPED,
            result: $result,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        return true;
    }

    private function markFailed(
        ScheduledMessageDeliveryAttempt $deliveryAttempt,
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
            claimedAttempt: $deliveryAttempt,
            status: ScheduledMessage::STATUS_FAILED,
            result: $result,
            exception: $exception,
        );

        if (! $completed instanceof ScheduledMessage) {
            return false;
        }

        return true;
    }

    private function shouldRetry(
        ScheduledMessageDeliveryAttempt $deliveryAttempt,
        Throwable $exception,
        ScheduledMessageDeliveryLeaseManager $deliveryLeaseManager,
    ): bool {
        if ($exception instanceof InvalidArgumentException) {
            return false;
        }

        return (int) $deliveryAttempt->attempt_number < $this->tries
            && $deliveryLeaseManager->canRetryAfterProviderSubmission(
                $deliveryAttempt,
            );
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

        $terminalResult = $this->terminalResult($currentMessage);

        $permissionInvitationService->markFailed(
            invitation: $permissionInvitation,
            scheduledMessage: $scheduledMessage,
            reason: $terminalResult->reason ?? $reason,
            failedAt: $terminalResult->occurredAt,
        );
    }

    private function terminalResult(
        ScheduledMessage $scheduledMessage,
    ): ScheduledMessageTerminalResult {
        $currentMessage = $scheduledMessage->fresh();

        if (! $currentMessage instanceof ScheduledMessage) {
            throw new RuntimeException(
                "ScheduledMessage [{$scheduledMessage->getKey()}] no longer exists.",
            );
        }

        return ScheduledMessageTerminalResult::fromScheduledMessage(
            $currentMessage->load('terminalOutboxEvent.deliveryAttempt'),
        );
    }
}