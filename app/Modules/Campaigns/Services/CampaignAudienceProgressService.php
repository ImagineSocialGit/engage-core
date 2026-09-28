<?php

namespace App\Modules\Campaigns\Services;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class CampaignAudienceProgressService
{
    public const STATUSES = [
        MessageChainEnrollment::STATUS_ACTIVE,
        MessageChainEnrollment::STATUS_PAUSED,
        MessageChainEnrollment::STATUS_COMPLETED,
        MessageChainEnrollment::STATUS_EXITED,
        MessageChainEnrollment::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly CampaignEligibilityAuthoringService $eligibility,
        private readonly ContactVisibility $visibility,
    ) {}

    /** @return array{matching: int, not_started: int, enrolled: int, statuses: array<string, int>} */
    public function summary(Campaign $campaign, User $user): array
    {
        return $campaign->usesRecurringAllocation()
            ? $this->allocationSummary($campaign, $user)
            : $this->sequenceSummary($campaign, $user);
    }

    /** @return LengthAwarePaginator<array<string, mixed>> */
    public function participants(
        Campaign $campaign,
        User $user,
        ?string $status = null,
        string $search = '',
    ): LengthAwarePaginator {
        return $campaign->usesRecurringAllocation()
            ? $this->allocationParticipants($campaign, $user, $status, $search)
            : $this->sequenceParticipants($campaign, $user, $status, $search);
    }

    /** @return LengthAwarePaginator<array<string, mixed>> */
    public function matches(
        Campaign $campaign,
        User $user,
        string $search = '',
    ): LengthAwarePaginator {
        $query = $this->visibleMatches($campaign, $user);

        if ($search !== '') {
            $this->search($query, $search);
        }

        $page = $query->reorder()->orderBy('contacts.id')
            ->paginate(25)->withQueryString();

        if ($campaign->usesRecurringAllocation()) {
            $latest = $this->latestAllocationEnrollments($campaign, $user)
                ->whereIn('contact_id', $page->getCollection()->modelKeys())
                ->get()
                ->keyBy('contact_id');

            return $page->through(function (Contact $contact) use ($latest): array {
                $enrollment = $latest->get($contact->getKey());

                return [
                    'contact' => $contact,
                    'name' => $this->contactName($contact),
                    'status' => $enrollment instanceof CampaignAllocationEnrollment
                        ? (string) $enrollment->status
                        : 'not_started',
                ];
            });
        }

        $latest = $this->latestSequentialEnrollments($campaign, $user)
            ->whereIn('contact_id', $page->getCollection()->modelKeys())
            ->with('messageChainEnrollment')
            ->get()
            ->keyBy('contact_id');

        return $page->through(function (Contact $contact) use ($latest): array {
            $enrollment = $latest->get($contact->getKey());

            return [
                'contact' => $contact,
                'name' => $this->contactName($contact),
                'status' => $enrollment instanceof CampaignEnrollment
                    ? ($enrollment->messageChainEnrollment?->status ?? 'unknown')
                    : 'not_started',
            ];
        });
    }

    /** @return array{matching: int, not_started: int, enrolled: int, statuses: array<string, int>} */
    private function sequenceSummary(Campaign $campaign, User $user): array
    {
        $matches = $this->visibleMatches($campaign, $user);
        $matchingCount = (clone $matches)->count();
        $notStarted = (clone $matches)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('campaign_enrollments')
                ->whereColumn('campaign_enrollments.contact_id', 'contacts.id')
                ->where('campaign_enrollments.campaign_id', $campaign->getKey()))
            ->count();

        $counts = $this->latestSequentialEnrollments($campaign, $user)
            ->leftJoin(
                'message_chain_enrollments as runtime',
                'runtime.id',
                '=',
                'campaign_enrollments.message_chain_enrollment_id',
            )
            ->selectRaw('runtime.status AS runtime_status, COUNT(*) AS total')
            ->groupBy('runtime.status')
            ->pluck('total', 'runtime_status');
        $statuses = $this->emptyStatuses();

        foreach (self::STATUSES as $status) {
            $statuses[$status] = (int) ($counts[$status] ?? 0);
        }

        $statuses['unknown'] = (int) ($counts[''] ?? 0);

        return [
            'matching' => $matchingCount,
            'not_started' => $notStarted,
            'enrolled' => array_sum($statuses),
            'statuses' => $statuses,
        ];
    }

    /** @return array{matching: int, not_started: int, enrolled: int, statuses: array<string, int>} */
    private function allocationSummary(Campaign $campaign, User $user): array
    {
        $matches = $this->visibleMatches($campaign, $user);
        $matchingCount = (clone $matches)->count();
        $notStarted = (clone $matches)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('campaign_allocation_enrollments')
                ->whereColumn('campaign_allocation_enrollments.contact_id', 'contacts.id')
                ->where('campaign_allocation_enrollments.campaign_id', $campaign->getKey()))
            ->count();

        $counts = $this->latestAllocationEnrollments($campaign, $user)
            ->selectRaw('campaign_allocation_enrollments.status AS allocation_status, COUNT(*) AS total')
            ->groupBy('campaign_allocation_enrollments.status')
            ->pluck('total', 'allocation_status');
        $statuses = $this->emptyStatuses();
        $statuses[MessageChainEnrollment::STATUS_ACTIVE] = (int) ($counts[CampaignAllocationEnrollment::STATUS_ACTIVE] ?? 0);
        $statuses[MessageChainEnrollment::STATUS_COMPLETED] = (int) ($counts[CampaignAllocationEnrollment::STATUS_COMPLETED] ?? 0);
        $statuses[MessageChainEnrollment::STATUS_CANCELLED] = (int) ($counts[CampaignAllocationEnrollment::STATUS_CANCELLED] ?? 0);

        return [
            'matching' => $matchingCount,
            'not_started' => $notStarted,
            'enrolled' => array_sum($statuses),
            'statuses' => $statuses,
        ];
    }

    /** @return LengthAwarePaginator<array<string, mixed>> */
    private function sequenceParticipants(
        Campaign $campaign,
        User $user,
        ?string $status,
        string $search,
    ): LengthAwarePaginator {
        $query = $this->latestSequentialEnrollments($campaign, $user)
            ->with([
                'contact',
                'messageChainEnrollment.currentMessageChainStep',
                'messageChainEnrollment.messageChainVersion',
                'messageChainEnrollment.latestScheduledMessage.latestDeliveryAttempt',
                'messageChainEnrollment.latestScheduledMessage.messageChainStepVariant.messageChainStep',
            ]);

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $query->whereHas('messageChainEnrollment', fn (Builder $runtime) => $runtime
                ->where('status', $status));
        }

        if ($search !== '') {
            $query->whereHas('contact', fn (Builder $contacts) => $this->search($contacts, $search));
        }

        return $query
            ->orderByDesc('campaign_enrollments.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CampaignEnrollment $enrollment): array => $this->sequenceParticipantRow($enrollment));
    }

    /** @return LengthAwarePaginator<array<string, mixed>> */
    private function allocationParticipants(
        Campaign $campaign,
        User $user,
        ?string $status,
        string $search,
    ): LengthAwarePaginator {
        $query = $this->latestAllocationEnrollments($campaign, $user)
            ->with([
                'contact',
                'latestAssignment.messageChainVersion.steps',
                'latestAssignment.scheduledMessage.latestDeliveryAttempt',
            ]);

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $mapped = match ($status) {
                MessageChainEnrollment::STATUS_ACTIVE => CampaignAllocationEnrollment::STATUS_ACTIVE,
                MessageChainEnrollment::STATUS_COMPLETED => CampaignAllocationEnrollment::STATUS_COMPLETED,
                MessageChainEnrollment::STATUS_CANCELLED => CampaignAllocationEnrollment::STATUS_CANCELLED,
                default => null,
            };

            if ($mapped === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('campaign_allocation_enrollments.status', $mapped);
            }
        }

        if ($search !== '') {
            $query->whereHas('contact', fn (Builder $contacts) => $this->search($contacts, $search));
        }

        return $query
            ->orderByDesc('campaign_allocation_enrollments.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CampaignAllocationEnrollment $enrollment): array => $this->allocationParticipantRow($enrollment));
    }

    /** @return Builder<CampaignEnrollment> */
    private function latestSequentialEnrollments(Campaign $campaign, User $user): Builder
    {
        return CampaignEnrollment::query()
            ->where('campaign_enrollments.campaign_id', $campaign->getKey())
            ->whereHas('contact', fn (Builder $query) => $this->visibility->apply($query, $user))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('campaign_enrollments as later')
                ->whereColumn('later.contact_id', 'campaign_enrollments.contact_id')
                ->where('later.campaign_id', $campaign->getKey())
                ->whereColumn('later.id', '>', 'campaign_enrollments.id'));
    }

    /** @return Builder<CampaignAllocationEnrollment> */
    private function latestAllocationEnrollments(Campaign $campaign, User $user): Builder
    {
        return CampaignAllocationEnrollment::query()
            ->where('campaign_allocation_enrollments.campaign_id', $campaign->getKey())
            ->whereHas('contact', fn (Builder $query) => $this->visibility->apply($query, $user))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('campaign_allocation_enrollments as later')
                ->whereColumn('later.contact_id', 'campaign_allocation_enrollments.contact_id')
                ->where('later.campaign_id', $campaign->getKey())
                ->whereColumn('later.id', '>', 'campaign_allocation_enrollments.id'));
    }

    /** @return Builder<Contact> */
    private function visibleMatches(Campaign $campaign, User $user): Builder
    {
        return $this->visibility->apply(
            $this->eligibility->matchingQuery($campaign),
            $user,
        );
    }

    /** @param Builder<Contact> $query */
    private function search(Builder $query, string $term): Builder
    {
        $term = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $contact) => $contact
            ->where('contacts.name', 'like', $term)
            ->orWhere('contacts.first_name', 'like', $term)
            ->orWhere('contacts.last_name', 'like', $term)
            ->orWhere('contacts.email', 'like', $term)
            ->orWhere('contacts.phone', 'like', $term));
    }

    /** @return array<string, mixed> */
    private function sequenceParticipantRow(CampaignEnrollment $enrollment): array
    {
        $contact = $enrollment->contact;
        $runtime = $enrollment->messageChainEnrollment;
        $message = $runtime?->latestScheduledMessage;
        $status = $runtime?->status ?? 'unknown';

        return [
            'contact' => $contact,
            'name' => $contact instanceof Contact ? $this->contactName($contact) : 'Lead unavailable',
            'status' => $status,
            'step' => $runtime?->currentMessageChainStep?->name
                ?? $message?->messageChainStepVariant?->messageChainStep?->name,
            'version' => $runtime?->messageChainVersion?->version,
            'next_at' => $runtime?->next_action_at,
            'started_at' => $enrollment->started_at,
            'message_status' => $message?->status,
            'reason' => match ($status) {
                MessageChainEnrollment::STATUS_EXITED => $runtime?->exit_reason_code,
                MessageChainEnrollment::STATUS_CANCELLED => data_get($enrollment->meta, 'lifecycle.last_cancellation.reason'),
                MessageChainEnrollment::STATUS_PAUSED => data_get($enrollment->meta, 'lifecycle.last_pause.reason'),
                default => in_array($message?->status, [
                    ScheduledMessage::STATUS_SKIPPED,
                    ScheduledMessage::STATUS_FAILED,
                ], true) ? $message?->latestDeliveryAttempt?->reason : null,
            },
        ];
    }

    /** @return array<string, mixed> */
    private function allocationParticipantRow(CampaignAllocationEnrollment $enrollment): array
    {
        $contact = $enrollment->contact;
        $assignment = $enrollment->latestAssignment;
        $message = $assignment?->scheduledMessage;
        $version = $assignment?->messageChainVersion;
        $step = $version?->steps->first(
            fn (MessageChainStep $candidate): bool =>
                (string) $candidate->key === (string) $assignment?->message_step_key,
        );

        return [
            'contact' => $contact,
            'name' => $contact instanceof Contact ? $this->contactName($contact) : 'Lead unavailable',
            'status' => (string) $enrollment->status,
            'step' => $step?->name,
            'version' => $version?->version,
            'next_at' => $message?->status === ScheduledMessage::STATUS_PENDING
                ? $message->send_at
                : null,
            'started_at' => $enrollment->started_at,
            'message_status' => $message?->status,
            'reason' => $enrollment->status === CampaignAllocationEnrollment::STATUS_CANCELLED
                ? data_get($enrollment->meta, 'lifecycle.last_cancellation.reason')
                : (in_array($message?->status, [
                    ScheduledMessage::STATUS_SKIPPED,
                    ScheduledMessage::STATUS_FAILED,
                ], true) ? $message?->latestDeliveryAttempt?->reason : null),
        ];
    }

    /** @return array<string, int> */
    private function emptyStatuses(): array
    {
        $statuses = array_fill_keys(self::STATUSES, 0);
        $statuses['unknown'] = 0;

        return $statuses;
    }

    private function contactName(Contact $contact): string
    {
        return trim((string) ($contact->name ?: trim($contact->first_name.' '.$contact->last_name)))
            ?: (string) ($contact->email ?: 'Lead #'.$contact->getKey());
    }
}