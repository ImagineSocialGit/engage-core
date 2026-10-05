<?php

namespace App\Support\ModuleIntegrations\Portal\Messaging;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Services\PortalSecretLinkCodec;
use InvalidArgumentException;

final class MessagingPortalAccountNotificationTransport implements PortalAccountNotificationTransport
{
    public function __construct(
        private readonly DispatchMessageAction $dispatch,
        private readonly PortalSecretLinkCodec $codec,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function deliverInvitation(
        PortalInvitation $invitation,
        string $token,
    ): bool {
        $channel = strtolower(trim((string) $invitation->channel));
        $destination = match ($channel) {
            'email' => $invitation->email,
            'sms' => $invitation->phone,
            default => null,
        };

        if (! is_string($destination) || trim($destination) === '') {
            return false;
        }

        $actionUrl = route('portal.invitations.accept', [
            'invitation' => $invitation,
            'token' => $this->codec->encode(
                PortalSecretLinkCodec::PURPOSE_INVITATION,
                $token,
            ),
        ]);

        return $this->dispatchPortalMessage(
            recipient: $invitation,
            channel: $channel,
            messageType: 'portal_invitation',
            destination: trim($destination),
            actionUrl: $actionUrl,
            behaviorOwner: $invitation,
            occurrenceKey: 'portal-invitation:'
                .$invitation->getKey().':'
                .hash('sha256', (string) $invitation->token_hash),
        );
    }

    public function deliverEmailVerification(
        PortalUser $user,
        string $verificationUrl,
    ): bool {
        if (! is_string($user->email) || trim($user->email) === '') {
            return false;
        }

        return $this->dispatchPortalMessage(
            recipient: $user,
            channel: 'email',
            messageType: 'portal_email_verification',
            destination: trim($user->email),
            actionUrl: $verificationUrl,
            behaviorOwner: $user,
            occurrenceKey: 'portal-email-verification:'
                .$user->getKey().':'
                .hash('sha256', $verificationUrl),
        );
    }

    public function deliverPasswordReset(
        PortalUser $user,
        string $token,
    ): bool {
        if (! is_string($user->email) || trim($user->email) === '') {
            return false;
        }

        $actionUrl = route('portal.password.reset', [
            'token' => $this->codec->encode(
                PortalSecretLinkCodec::PURPOSE_PASSWORD_RESET,
                $token,
            ),
            'email' => $user->email,
        ]);

        return $this->dispatchPortalMessage(
            recipient: $user,
            channel: 'email',
            messageType: 'portal_password_reset',
            destination: trim($user->email),
            actionUrl: $actionUrl,
            behaviorOwner: $user,
            occurrenceKey: 'portal-password-reset:'
                .$user->getKey().':'
                .hash('sha256', $token),
        );
    }

    private function dispatchPortalMessage(
        PortalInvitation|PortalUser $recipient,
        string $channel,
        string $messageType,
        string $destination,
        string $actionUrl,
        PortalInvitation|PortalUser $behaviorOwner,
        string $occurrenceKey,
    ): bool {
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException('Unsupported Portal notification channel.');
        }

        $content = (array) config(
            "portal.notifications.{$messageType}.{$channel}",
            [],
        );

        $payload = $channel === 'email'
            ? [
                'to' => $destination,
                'subject' => $this->requiredString($content, 'subject'),
                'body' => $this->requiredString($content, 'body'),
                'cta' => [
                    'label' => $this->requiredString($content, 'action_label'),
                    'url' => $actionUrl,
                ],
                'tokens' => ['action_url' => $actionUrl],
            ]
            : [
                'to' => $destination,
                'message' => $this->requiredString($content, 'message'),
                'tokens' => ['action_url' => $actionUrl],
                'meta' => ['prefix_brand' => true],
            ];

        $messages = $this->dispatch->handle(
            recipient: $recipient,
            channel: $channel,
            purpose: 'transactional',
            scope: 'portal',
            dispatchKeys: $messageType,
            payload: $payload,
            context: $recipient,
            sendAt: now(),
            behaviorOwner: $behaviorOwner,
            occurrenceKey: $occurrenceKey,
            definitions: [[
                'dispatch_key' => $messageType,
                'message_type' => $messageType,
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'portal',
                'payload_class' => $channel === 'email'
                    ? EmailPayload::class
                    : SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [],
            ]],
        );

        return $messages !== [];
    }

    /** @param array<string, mixed> $content */
    private function requiredString(array $content, string $key): string
    {
        $value = $content[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Portal notification content [{$key}] is not configured.",
            );
        }

        return trim($value);
    }
}