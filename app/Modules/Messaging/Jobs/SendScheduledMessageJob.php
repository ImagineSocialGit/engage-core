<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<string, mixed> $horizon
     */
    public function __construct(
        public int $scheduledMessageId,
        public array $horizon = [],
    ) {}

    public function handle(
        ScheduledMessageGate $scheduledMessageGate,
        EmailMessagingService $emailMessagingService,
        SmsMessagingService $smsMessagingService,
        ContactPermissionInvitationService $permissionInvitationService,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->with(['recipient', 'context'])
            ->find($this->scheduledMessageId);

        if (! $scheduledMessage || $scheduledMessage->status !== ScheduledMessage::STATUS_PENDING) {
            return;
        }

        if ($denialReason = $scheduledMessageGate->denialReason($scheduledMessage)) {
            $this->markSkipped($scheduledMessage, $denialReason);

            return;
        }

        $permissionInvitation = $this->claimPermissionInvitation(
            scheduledMessage: $scheduledMessage,
            permissionInvitationService: $permissionInvitationService,
        );

        if ($permissionInvitationService->isImportedContactPermissionInvitationMessage($scheduledMessage)
            && ! $permissionInvitation
        ) {
            $this->markSkipped($scheduledMessage, 'Imported contact permission invitation was already used.');

            return;
        }

        if ($permissionInvitation) {
            $this->applyPermissionInvitationPayload(
                scheduledMessage: $scheduledMessage,
                permissionInvitation: $permissionInvitation,
                permissionInvitationService: $permissionInvitationService,
            );
        }

        try {
            $payload = $this->resolvePayload($scheduledMessage);

            if ($reason = $this->unresolvedTokenReason($payload)) {
                $this->markSkipped($scheduledMessage, $reason);

                return;
            }

            match ($scheduledMessage->channel) {
                MessageChannel::Email->value => $this->sendEmail($payload, $emailMessagingService),
                MessageChannel::Sms->value => $this->sendSms($payload, $smsMessagingService),
                default => throw new InvalidArgumentException("Unsupported message channel [{$scheduledMessage->channel}]."),
            };

            $scheduledMessage->forceFill([
                'status' => ScheduledMessage::STATUS_SENT,
                'sent_at' => now(),
                'failure_reason' => null,
            ])->save();

            if ($permissionInvitation) {
                $permissionInvitationService->markSent($permissionInvitation, $scheduledMessage);
            }

            ScheduledMessageSent::dispatch($scheduledMessage);
        } catch (Throwable $exception) {
            $scheduledMessage->forceFill([
                'status' => ScheduledMessage::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ])->save();

            if ($permissionInvitation) {
                $permissionInvitationService->markFailed(
                    invitation: $permissionInvitation,
                    scheduledMessage: $scheduledMessage,
                    reason: $exception->getMessage(),
                );
            }

            ScheduledMessageFailed::dispatch($scheduledMessage);

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

        $payload = $payloadClass::fromArray(array_replace_recursive(
            [
                'channel' => $scheduledMessage->channel,
                'purpose' => $scheduledMessage->purpose,
                'scope' => $scheduledMessage->scope,
                'message_type' => $scheduledMessage->message_type,
            ],
            $scheduledMessage->payload ?? [],
        ));

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

            if (! is_string($value['label'] ?? null) || trim($value['label']) === '') {
                continue;
            }

            if (! is_string($value['url'] ?? null) || trim($value['url']) === '') {
                continue;
            }

            $tokens[] = '{'.$key.'}';
        }

        return $tokens;
    }

    private function sendEmail(
        EmailMessage|SmsMessage $payload,
        EmailMessagingService $emailMessagingService,
    ): void {
        if (! $payload instanceof EmailMessage) {
            throw new InvalidArgumentException('Scheduled email message resolved to a non-email payload.');
        }

        $emailMessagingService->send($payload);
    }

    private function sendSms(
        EmailMessage|SmsMessage $payload,
        SmsMessagingService $smsMessagingService,
    ): void {
        if (! $payload instanceof SmsMessage) {
            throw new InvalidArgumentException('Scheduled SMS message resolved to a non-SMS payload.');
        }

        $smsMessagingService->send($payload);
    }

    private function markSkipped(ScheduledMessage $scheduledMessage, string $reason): void
    {
        $scheduledMessage->forceFill([
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => $reason,
            'failure_reason' => null,
        ])->save();

        ScheduledMessageSkipped::dispatch($scheduledMessage);
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
}