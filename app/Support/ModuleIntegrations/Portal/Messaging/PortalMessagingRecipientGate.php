<?php

namespace App\Support\ModuleIntegrations\Portal\Messaging;

use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Services\MessageSuppressionService;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Model;

final class PortalMessagingRecipientGate implements MessageRecipientGate
{
    public function __construct(
        private readonly MessageSuppressionService $suppressions,
    ) {}

    public function supports(Model $recipient): bool
    {
        return $recipient instanceof PortalInvitation
            || $recipient instanceof PortalUser;
    }

    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason($recipient, $channel, $type, $context) === null;
    }

    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        if (! $this->supports($recipient)) {
            return null;
        }

        $channel = strtolower(trim($channel));
        $type = is_string($type) ? trim($type) : null;
        $purpose = strtolower(trim((string) ($context['purpose'] ?? '')));
        $scope = strtolower(trim((string) ($context['scope'] ?? '')));

        if ($purpose !== 'transactional' || $scope !== 'portal') {
            return 'Portal account notifications must be transactional Portal messages.';
        }

        if ($recipient instanceof PortalInvitation) {
            return $this->invitationDenialReason(
                invitation: $recipient,
                channel: $channel,
                type: $type,
                context: $context,
            );
        }

        return $this->userDenialReason(
            user: $recipient,
            channel: $channel,
            type: $type,
            context: $context,
        );
    }

    /** @param array<string, mixed> $context */
    private function invitationDenialReason(
        PortalInvitation $invitation,
        string $channel,
        ?string $type,
        array $context,
    ): ?string {
        if ($type !== 'portal_invitation') {
            return 'Portal invitation recipient does not support this message type.';
        }

        if ($invitation->status !== PortalInvitation::STATUS_PENDING) {
            return 'Portal invitation is no longer pending delivery.';
        }

        if ($invitation->expires_at !== null && ! $invitation->expires_at->isFuture()) {
            return 'Portal invitation has expired.';
        }

        if ($channel !== $invitation->channel) {
            return 'Portal invitation channel no longer matches the scheduled message.';
        }

        $destination = $channel === 'email'
            ? $invitation->email
            : ($channel === 'sms' ? $invitation->phone : null);

        return $this->destinationDenialReason($destination, $channel, $context);
    }

    /** @param array<string, mixed> $context */
    private function userDenialReason(
        PortalUser $user,
        string $channel,
        ?string $type,
        array $context,
    ): ?string {
        if (! in_array($type, [
            'portal_email_verification',
            'portal_password_reset',
        ], true)) {
            return 'Portal account recipient does not support this message type.';
        }

        if ($user->status !== PortalUser::STATUS_ACTIVE || $user->trashed()) {
            return 'Portal account is not active.';
        }

        if ($channel !== 'email') {
            return 'Portal account notification requires email delivery.';
        }

        if ($type === 'portal_email_verification' && $user->hasVerifiedEmail()) {
            return 'Portal account email is already verified.';
        }

        return $this->destinationDenialReason($user->email, $channel, $context);
    }

    /** @param array<string, mixed> $context */
    private function destinationDenialReason(
        mixed $destination,
        string $channel,
        array $context,
    ): ?string {
        if (! is_string($destination) || trim($destination) === '') {
            return 'Portal notification destination is missing.';
        }

        $payload = is_array($context['payload'] ?? null)
            ? $context['payload']
            : [];
        $scheduledDestination = $payload['to'] ?? null;

        if (is_string($scheduledDestination)
            && trim($scheduledDestination) !== ''
            && trim($scheduledDestination) !== trim($destination)
        ) {
            return 'Portal notification destination no longer matches the account record.';
        }

        if ($this->suppressions->isSuppressed($channel, trim($destination))) {
            return 'Portal notification destination is suppressed.';
        }

        return null;
    }
}