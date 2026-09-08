<?php

namespace App\Modules\Broadcasts\Services;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class BroadcastAudiencePreviewService
{
    private const CONTACT_PREVIEW_LIMIT = 100;

    public function __construct(
        private readonly BroadcastRecipientResolver $recipientResolver,
    ) {}

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function preview(array $filter, ?User $actor = null): array
    {
        $broadcast = new Broadcast([
            'user_id' => $actor?->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'recipient_filter' => $filter,
        ]);

        $audience = $this->recipientResolver->query($broadcast)->reorder();
        $selectedCount = (int) (clone $audience)->count('contacts.id');

        if ($selectedCount === 0) {
            return [
                'selected_count' => 0,
                'without_any_consent_count' => 0,
                'previous_broadcasts' => [],
                'overlapping_broadcast_ids' => [],
                'overlap_contact_count' => 0,
                'contacts' => [],
                'contacts_truncated' => false,
            ];
        }

        $withoutAnyConsentCount = (int) (clone $audience)
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('message_consents')
                    ->whereColumn('message_consents.contact_id', 'contacts.id');
            })
            ->count('contacts.id');

        $contacts = (clone $audience)
            ->orderBy('contacts.name')
            ->orderBy('contacts.email')
            ->orderBy('contacts.id')
            ->limit(self::CONTACT_PREVIEW_LIMIT)
            ->get([
                'contacts.id',
                'contacts.name',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.email',
                'contacts.phone',
            ])
            ->map(fn (Contact $contact): array => [
                'id' => (int) $contact->getKey(),
                'name' => $this->contactLabel($contact),
                'email' => is_string($contact->email) ? $contact->email : null,
                'phone' => is_string($contact->phone) ? $contact->phone : null,
            ])
            ->values()
            ->all();

        $overlap = $this->previousBroadcastOverlap($audience);

        return [
            'selected_count' => $selectedCount,
            'without_any_consent_count' => $withoutAnyConsentCount,
            'previous_broadcasts' => $overlap['broadcasts'],
            'overlapping_broadcast_ids' => $overlap['broadcast_ids'],
            'overlap_contact_count' => $overlap['contact_count'],
            'contacts' => $contacts,
            'contacts_truncated' => $selectedCount > count($contacts),
        ];
    }

    /**
     * @param Builder<Contact> $audience
     * @return array{
     *     broadcasts: array<int, array<string, mixed>>,
     *     broadcast_ids: array<int, int>,
     *     contact_count: int
     * }
     */
    private function previousBroadcastOverlap(Builder $audience): array
    {
        $audienceIds = (clone $audience)->select('contacts.id');
        $base = $this->overlapQuery($audienceIds);

        $broadcastIds = (clone $base)
            ->distinct()
            ->orderBy('broadcasts.id')
            ->pluck('broadcasts.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $contactCount = (int) (clone $base)
            ->distinct()
            ->count('broadcast_recipients.contact_id');

        $broadcasts = (clone $base)
            ->groupBy('broadcasts.id', 'broadcasts.name', 'broadcasts.channel', 'broadcasts.created_at')
            ->orderByDesc('broadcasts.created_at')
            ->limit(10)
            ->get([
                'broadcasts.id',
                'broadcasts.name',
                'broadcasts.channel',
                DB::raw("SUM(CASE WHEN broadcast_recipients.status = 'sent' THEN 1 ELSE 0 END) as sent_count"),
                DB::raw("SUM(CASE WHEN broadcast_recipients.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count"),
                DB::raw('COUNT(*) as overlap_count'),
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'channel' => (string) $row->channel,
                'sent_count' => (int) $row->sent_count,
                'scheduled_count' => (int) $row->scheduled_count,
                'overlap_count' => (int) $row->overlap_count,
            ])
            ->values()
            ->all();

        return [
            'broadcasts' => $broadcasts,
            'broadcast_ids' => $broadcastIds,
            'contact_count' => $contactCount,
        ];
    }

    /**
     * @param Builder<Contact> $audienceIds
     */
    private function overlapQuery(Builder $audienceIds): QueryBuilder
    {
        return DB::table('broadcast_recipients')
            ->join('broadcasts', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
            ->where('broadcasts.message_type', '!=', Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION)
            ->whereIn('broadcasts.status', [
                Broadcast::STATUS_SCHEDULED,
                Broadcast::STATUS_SENDING,
                Broadcast::STATUS_COMPLETED,
            ])
            ->whereIn('broadcast_recipients.status', [
                BroadcastRecipient::STATUS_SCHEDULED,
                BroadcastRecipient::STATUS_SENT,
            ])
            ->whereIn('broadcast_recipients.contact_id', $audienceIds);
    }

    private function contactLabel(Contact $contact): string
    {
        $name = trim((string) $contact->name);

        if ($name !== '') {
            return $name;
        }

        $splitName = trim(implode(' ', array_filter([
            trim((string) $contact->first_name),
            trim((string) $contact->last_name),
        ])));

        if ($splitName !== '') {
            return $splitName;
        }

        $email = trim((string) $contact->email);

        return $email !== '' ? $email : 'Contact #'.$contact->getKey();
    }
}