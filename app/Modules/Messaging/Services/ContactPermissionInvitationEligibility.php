<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;

class ContactPermissionInvitationEligibility
{
    public function __construct(
        private readonly ContactPermissionInvitationService $permissionInvitationService,
    ) {}

    public function eligibleForImportedContactEmailInvitation(Contact $contact): bool
    {
        if (! $this->isImportedContact($contact)) {
            return false;
        }

        if (! is_string($contact->email) || trim($contact->email) === '') {
            return false;
        }

        if ($this->permissionInvitationService->hasExistingImportedContactEmailInvitation($contact)) {
            return false;
        }

        if ($this->hasExistingImportedContactPermissionInvitationMessage($contact)) {
            return false;
        }

        foreach ($this->permissionInvitationService->consentScopes() as $scope) {
            if (! $this->hasActiveMarketingEmailConsent($contact, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveMarketingEmailConsent(Contact $contact, string $scope): bool
    {
        $consent = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('scope', $scope)
            ->whereNotNull('consented_at')
            ->first();

        if (! $consent) {
            return false;
        }

        return ! ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('scope', $scope)
            ->where('revoked_at', '>=', $consent->consented_at)
            ->exists();
    }

    private function hasExistingImportedContactPermissionInvitationMessage(Contact $contact): bool
    {
        return ScheduledMessage::query()
            ->where('recipient_type', $contact->getMorphClass())
            ->where('recipient_id', $contact->getKey())
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('message_type', ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION)
            ->whereIn('status', [
                ScheduledMessage::STATUS_PENDING,
                ScheduledMessage::STATUS_SENT,
            ])
            ->exists();
    }

    private function isImportedContact(Contact $contact): bool
    {
        $source = is_string($contact->source)
            ? str_replace('-', '_', strtolower(trim($contact->source)))
            : null;

        if ($source === 'import') {
            return true;
        }

        if ($contact->contact_import_batch_id !== null) {
            return true;
        }

        $meta = is_array($contact->meta) ? $contact->meta : [];

        return (bool) ($meta['imported'] ?? false)
            || array_key_exists('imported_at', $meta);
    }
}