<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\QueryException;

class ContactPermissionInvitationService
{
    public const MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION = 'imported_contact_permission_invitation';

    /**
     * @param array<string, mixed> $context
     */
    public function allowsImportedContactInvitationPass(
        Contact $contact,
        string $channel,
        string $messageType,
        array $context,
    ): bool {
        if (! $this->isImportedContactPermissionInvitationPolicy($context)) {
            return false;
        }

        if ($channel !== MessageChannel::Email->value) {
            return false;
        }

        if ($messageType !== self::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION) {
            return false;
        }

        if (! $this->isImportedContact($contact)) {
            return false;
        }

        return ! $this->hasExistingImportedContactEmailInvitation($contact);
    }

    public function isImportedContactPermissionInvitationMessage(ScheduledMessage $scheduledMessage): bool
    {
        if ($scheduledMessage->channel !== MessageChannel::Email->value) {
            return false;
        }

        if ($scheduledMessage->message_type !== self::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION) {
            return false;
        }

        return $this->isImportedContactPermissionInvitationPolicy([
            'consent_policy' => $scheduledMessage->meta['consent_policy'] ?? [],
        ]);
    }

    public function claimForScheduledMessage(ScheduledMessage $scheduledMessage): ?ContactPermissionInvitation
    {
        if (! $this->isImportedContactPermissionInvitationMessage($scheduledMessage)) {
            return null;
        }

        $contact = $scheduledMessage->recipient;

        if (! $contact instanceof Contact) {
            return null;
        }

        if (! $this->isImportedContact($contact)) {
            return null;
        }

        try {
            return ContactPermissionInvitation::query()->create([
                'contact_id' => $contact->getKey(),
                'scheduled_message_id' => $scheduledMessage->getKey(),
                'context_type' => $scheduledMessage->context_type,
                'context_id' => $scheduledMessage->context_id,
                'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
                'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                'status' => ContactPermissionInvitation::STATUS_CLAIMED,
                'claimed_at' => now(),
                'meta' => [
                    'scheduled_message' => [
                        'message_type' => $scheduledMessage->message_type,
                        'purpose' => $scheduledMessage->purpose,
                        'scope' => $scheduledMessage->scope,
                    ],
                ],
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    public function markSent(
        ContactPermissionInvitation $invitation,
        ScheduledMessage $scheduledMessage,
    ): void {
        $sentAt = $scheduledMessage->sent_at ?? now();

        $invitation->forceFill([
            'scheduled_message_id' => $scheduledMessage->getKey(),
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'sent_at' => $sentAt,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(
        ContactPermissionInvitation $invitation,
        ScheduledMessage $scheduledMessage,
        string $reason,
    ): void {
        $invitation->forceFill([
            'scheduled_message_id' => $scheduledMessage->getKey(),
            'status' => ContactPermissionInvitation::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }

    public function hasExistingImportedContactEmailInvitation(Contact $contact): bool
    {
        return ContactPermissionInvitation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
            ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
            ->exists();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isImportedContactPermissionInvitationPolicy(array $context): bool
    {
        $policy = $context['consent_policy']['permission_invitation']
            ?? $context['permission_invitation']
            ?? [];

        if (! is_array($policy)) {
            return false;
        }

        return ($policy['source'] ?? null) === ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT
            && (bool) ($policy['one_time'] ?? false);
    }

    private function isImportedContact(Contact $contact): bool
    {
        $source = is_string($contact->source)
            ? str_replace('-', '_', strtolower(trim($contact->source)))
            : null;

        if ($source === 'import') {
            return true;
        }

        $meta = is_array($contact->meta) ? $contact->meta : [];

        return (bool) ($meta['imported'] ?? false)
            || array_key_exists('imported_at', $meta);
    }
}