<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BroadcastRecipientResolver
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
    ) {}

    /**
     * @return Builder<Contact>
     */
    public function query(Broadcast $broadcast): Builder
    {
        $query = $this->contactFilterResolver->query(
            $broadcast->recipient_filter ?? [],
        );

        $this->applyPriorBroadcastExclusions($broadcast, $query);

        if ($this->shouldExcludePermissionInvitationIneligibleContacts($broadcast)) {
            $this->applyPermissionInvitationEligibility($query);
        }

        return $query;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function resolve(Broadcast $broadcast): Collection
    {
        return $this->query($broadcast)->get();
    }

    public function count(Broadcast $broadcast): int
    {
        return (int) $this->query($broadcast)
            ->reorder()
            ->count('contacts.id');
    }

    /**
     * Persist the current eligible recipient set without materializing the
     * whole Contact collection in PHP. Existing rows make retries idempotent.
     */
    public function snapshot(Broadcast $broadcast): int
    {
        $now = now();
        $source = $this->query($broadcast)
            ->reorder()
            ->orderBy('contacts.id')
            ->selectRaw('? as broadcast_id', [$broadcast->getKey()])
            ->addSelect('contacts.id as contact_id')
            ->selectRaw('? as status', [BroadcastRecipient::STATUS_PENDING])
            ->selectRaw('NULL as scheduled_message_id')
            ->selectRaw('NULL as sent_at')
            ->selectRaw('NULL as terminal_reason')
            ->selectRaw('NULL as meta')
            ->selectRaw('? as created_at', [$now])
            ->selectRaw('? as updated_at', [$now]);

        return DB::table('broadcast_recipients')->insertOrIgnoreUsing([
            'broadcast_id',
            'contact_id',
            'status',
            'scheduled_message_id',
            'sent_at',
            'terminal_reason',
            'meta',
            'created_at',
            'updated_at',
        ], $source->toBase());
    }

    /**
     * @return array{
     *     imported_contacts_count: int,
     *     already_consented_count: int,
     *     already_invited_count: int,
     *     ineligible_contacts_count: int,
     *     eligible_contacts_count: int,
     *     excluded_by_prior_broadcast_count: int
     * }
     */
    public function permissionInvitationPreview(Broadcast $broadcast): array
    {
        $candidateQuery = $this->contactFilterResolver->query(
            $broadcast->recipient_filter ?? [],
        );
        $candidateCount = (int) (clone $candidateQuery)
            ->reorder()
            ->count('contacts.id');

        if ($candidateCount === 0) {
            return [
                'imported_contacts_count' => 0,
                'already_consented_count' => 0,
                'already_invited_count' => 0,
                'ineligible_contacts_count' => 0,
                'eligible_contacts_count' => 0,
                'excluded_by_prior_broadcast_count' => 0,
            ];
        }

        $afterPriorExclusions = clone $candidateQuery;
        $this->applyPriorBroadcastExclusions(
            $broadcast,
            $afterPriorExclusions,
        );

        $afterPriorCount = (int) (clone $afterPriorExclusions)
            ->reorder()
            ->count('contacts.id');

        $alreadyConsentedCount = (int) (clone $afterPriorExclusions)
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('message_consents')
                    ->whereColumn('message_consents.contact_id', 'contacts.id');
            })
            ->reorder()
            ->count('contacts.id');

        $alreadyInvitedCount = (int) (clone $afterPriorExclusions)
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('contact_permission_invitations')
                    ->whereColumn('contact_permission_invitations.contact_id', 'contacts.id')
                    ->where('contact_permission_invitations.channel', ContactPermissionInvitation::CHANNEL_EMAIL)
                    ->where('contact_permission_invitations.source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT);
            })
            ->reorder()
            ->count('contacts.id');

        $eligibleQuery = clone $afterPriorExclusions;
        $this->applyPermissionInvitationEligibility($eligibleQuery);
        $eligibleCount = (int) $eligibleQuery
            ->reorder()
            ->count('contacts.id');

        return [
            'imported_contacts_count' => $candidateCount,
            'already_consented_count' => $alreadyConsentedCount,
            'already_invited_count' => $alreadyInvitedCount,
            'ineligible_contacts_count' => max(0, $afterPriorCount - $eligibleCount),
            'eligible_contacts_count' => $eligibleCount,
            'excluded_by_prior_broadcast_count' => max(0, $candidateCount - $afterPriorCount),
        ];
    }

    /**
     * @param Builder<Contact> $query
     */
    private function applyPriorBroadcastExclusions(
        Broadcast $broadcast,
        Builder $query,
    ): void {
        $recipientFilter = $broadcast->recipient_filter ?? [];
        $exclude = is_array($recipientFilter['exclude'] ?? null)
            ? $recipientFilter['exclude']
            : [];

        $broadcastIds = $this->integerValues($exclude['broadcast_ids'] ?? []);
        $statuses = $this->broadcastRecipientStatuses($exclude['statuses'] ?? []);

        if ($broadcastIds === [] || $statuses === []) {
            return;
        }

        $query->whereNotExists(function ($subquery) use ($broadcastIds, $statuses): void {
            $subquery
                ->selectRaw('1')
                ->from('broadcast_recipients as excluded_broadcast_recipients')
                ->whereColumn('excluded_broadcast_recipients.contact_id', 'contacts.id')
                ->whereIn('excluded_broadcast_recipients.broadcast_id', $broadcastIds)
                ->whereIn('excluded_broadcast_recipients.status', $statuses);
        });
    }

    /**
     * @param Builder<Contact> $query
     */
    private function applyPermissionInvitationEligibility(Builder $query): void
    {
        $query
            ->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('message_consents')
                    ->whereColumn('message_consents.contact_id', 'contacts.id');
            })
            ->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('contact_permission_invitations')
                    ->whereColumn('contact_permission_invitations.contact_id', 'contacts.id')
                    ->where('contact_permission_invitations.channel', ContactPermissionInvitation::CHANNEL_EMAIL)
                    ->where('contact_permission_invitations.source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT);
            });
    }

    private function shouldExcludePermissionInvitationIneligibleContacts(Broadcast $broadcast): bool
    {
        return $broadcast->message_type === Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION
            && $broadcast->channel === 'email'
            && $broadcast->purpose === 'transactional'
            && $broadcast->scope === 'permission_invitation'
            && in_array(
                data_get($broadcast->recipient_filter, 'type'),
                ['imported', 'import_batch'],
                true,
            );
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
            fn (mixed $value): ?string => is_string($value)
                && in_array($value, $allowed, true)
                    ? $value
                    : null,
            $values,
        ))));
    }
}