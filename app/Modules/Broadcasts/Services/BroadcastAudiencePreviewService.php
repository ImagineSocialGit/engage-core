<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BroadcastAudiencePreviewService
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
    ) {}

    /**
     * @param array<string, mixed> $filter
     * @return array{
     *     selected_count: int,
     *     without_any_consent_count: int,
     *     previous_broadcasts: array<int, array{
     *         id: int,
     *         name: string,
     *         channel: string,
     *         sent_count: int,
     *         scheduled_count: int,
     *         overlap_count: int
     *     }>
     * }
     */
    public function preview(array $filter): array
    {
        $audience = $this->contactFilterResolver->query($filter)->reorder();
        $selectedCount = (int) (clone $audience)->count('contacts.id');

        if ($selectedCount === 0) {
            return [
                'selected_count' => 0,
                'without_any_consent_count' => 0,
                'previous_broadcasts' => [],
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


        return [
            'selected_count' => $selectedCount,
            'without_any_consent_count' => $withoutAnyConsentCount,
            'previous_broadcasts' => $this->previousBroadcastOverlap($audience),
        ];
    }

    /**
     * @param Builder<Contact> $audience
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     channel: string,
     *     sent_count: int,
     *     scheduled_count: int,
     *     overlap_count: int
     * }>
     */
    private function previousBroadcastOverlap(Builder $audience): array
    {
        $audienceIds = (clone $audience)->select('contacts.id');

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
            ->whereIn('broadcast_recipients.contact_id', $audienceIds)
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
    }
}