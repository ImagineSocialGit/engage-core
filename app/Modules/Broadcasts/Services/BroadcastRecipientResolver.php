<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Database\Eloquent\Collection;

class BroadcastRecipientResolver
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
    ) {}

    /**
     * @return Collection<int, Contact>
     */
    public function resolve(Broadcast $broadcast): Collection
    {
        $contacts = $this->contactFilterResolver->resolve($broadcast->recipient_filter ?? []);

        if ($contacts->isEmpty()) {
            return $contacts;
        }

        $contacts = $this->excludePriorBroadcastRecipients($broadcast, $contacts);

        if ($contacts->isEmpty()) {
            return $contacts;
        }

        if ($this->shouldExcludeContactsWithMessageConsent($broadcast)) {
            $contacts = $this->excludeContactsWithMessageConsent($contacts);
        }

        return $contacts->values();
    }


    /**
     * @return array{
     *     imported_contacts_count: int,
     *     already_consented_count: int,
     *     eligible_contacts_count: int,
     *     excluded_by_prior_broadcast_count: int
     * }
     */
    public function permissionInvitationPreview(Broadcast $broadcast): array
    {
        $importedContacts = $this->contactFilterResolver->resolve(['type' => 'imported']);

        if ($importedContacts->isEmpty()) {
            return [
                'imported_contacts_count' => 0,
                'already_consented_count' => 0,
                'eligible_contacts_count' => 0,
                'excluded_by_prior_broadcast_count' => 0,
            ];
        }

        $afterPriorBroadcastExclusions = $this->excludePriorBroadcastRecipients($broadcast, $importedContacts);
        $contactIdsWithConsent = $this->contactIdsWithMessageConsent($afterPriorBroadcastExclusions);

        return [
            'imported_contacts_count' => $importedContacts->count(),
            'already_consented_count' => count($contactIdsWithConsent),
            'eligible_contacts_count' => max(0, $afterPriorBroadcastExclusions->count() - count($contactIdsWithConsent)),
            'excluded_by_prior_broadcast_count' => max(0, $importedContacts->count() - $afterPriorBroadcastExclusions->count()),
        ];
    }

    /**
     * @param Collection<int, Contact> $contacts
     * @return Collection<int, Contact>
     */
    private function excludePriorBroadcastRecipients(Broadcast $broadcast, Collection $contacts): Collection
    {
        $recipientFilter = $broadcast->recipient_filter ?? [];
        $exclude = is_array($recipientFilter['exclude'] ?? null) ? $recipientFilter['exclude'] : [];

        $broadcastIds = $this->integerValues($exclude['broadcast_ids'] ?? []);
        $statuses = $this->broadcastRecipientStatuses($exclude['statuses'] ?? []);

        if ($broadcastIds === [] || $statuses === []) {
            return $contacts;
        }

        $excludedContactIds = BroadcastRecipient::query()
            ->whereIn('broadcast_id', $broadcastIds)
            ->whereIn('status', $statuses)
            ->whereIn('contact_id', $contacts->modelKeys())
            ->pluck('contact_id')
            ->all();

        if ($excludedContactIds === []) {
            return $contacts;
        }

        return $contacts->reject(
            fn (Contact $contact): bool => in_array($contact->getKey(), $excludedContactIds, true)
        );
    }

    /**
     * @param Collection<int, Contact> $contacts
     * @return Collection<int, Contact>
     */
    private function excludeContactsWithMessageConsent(Collection $contacts): Collection
    {
        $contactIdsWithConsent = $this->contactIdsWithMessageConsent($contacts);

        if ($contactIdsWithConsent === []) {
            return $contacts;
        }

        return $contacts->reject(
            fn (Contact $contact): bool => in_array($contact->getKey(), $contactIdsWithConsent, true)
        );
    }

    /**
     * @param Collection<int, Contact> $contacts
     * @return array<int, int>
     */
    private function contactIdsWithMessageConsent(Collection $contacts): array
    {
        if ($contacts->isEmpty()) {
            return [];
        }

        return MessageConsent::query()
            ->whereIn('contact_id', $contacts->modelKeys())
            ->distinct()
            ->pluck('contact_id')
            ->map(fn (mixed $contactId): int => (int) $contactId)
            ->all();
    }

    private function shouldExcludeContactsWithMessageConsent(Broadcast $broadcast): bool
    {
        return $broadcast->message_type === Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION
            && $broadcast->channel === 'email'
            && $broadcast->purpose === 'transactional'
            && $broadcast->scope === 'permission_invitation'
            && data_get($broadcast->recipient_filter, 'type') === 'imported';
    }

    /**
     * @return array<int, int>
     */
    private function integerValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /**
     * @return array<int, string>
     */
    private function broadcastRecipientStatuses(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $allowed = [
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && in_array($value, $allowed, true)
                ? $value
                : null,
            $values,
        ))));
    }
}