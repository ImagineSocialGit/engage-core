<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactIndexFilterService;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class OutboundMessageIndex
{
    public function __construct(
        private readonly ContactVisibility $contactVisibility,
        private readonly ContactIndexFilterService $contactFilters,
        private readonly ContactResultSetResolver $resultSets,
        private readonly ScheduledMessageContentEditor $contentEditor,
        private readonly UserAccessService $access,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->query($user, $filters)->with([
            'recipient',
            'context',
            'messageTemplateVersion',
            'messageChainEnrollment',
            'components.messageTemplateVersion',
            'latestContentEdit',
        ]);

        $period = $filters['period'] ?? 'upcoming';

        return $query
            ->orderBy('send_at', $period === 'past' ? 'desc' : 'asc')
            ->orderBy('id', $period === 'past' ? 'desc' : 'asc')
            ->paginate(30)
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function query(User $user, array $filters): Builder
    {
        $query = ScheduledMessage::query();
        $contactState = $this->contactFilters->state([
            'status' => $filters['contact_status'] ?? null,
            'tag' => $filters['tag'] ?? null,
            'search' => $filters['search'] ?? null,
        ]);
        $contacts = isset($filters['contact_group'])
            ? $this->resultSets->visibleQuery($filters['contact_group'], $user)
            : $this->contactVisibility->apply($this->contactFilters->query($contactState), $user);

        if (isset($filters['contact_group']) && $contactState['has_filters']) {
            $contacts->whereIn('contacts.id', $this->contactFilters
                ->query($contactState)->select('contacts.id'));
        }

        if (isset($filters['contact_id'])) {
            $contacts->whereKey((int) $filters['contact_id']);
        }

        $contactType = (new Contact())->getMorphClass();
        $contactScoped = isset($filters['contact_id']) || isset($filters['contact_group']) || $contactState['has_filters'];

        $query->where(function (Builder $visible) use ($contacts, $contactType, $user, $contactScoped): void {
            $visible->where(function (Builder $contactMessages) use ($contacts, $contactType): void {
                $contactMessages->where('recipient_type', $contactType)
                    ->whereIn('recipient_id', $contacts->select('contacts.id'));
            });

            if (! $contactScoped && $this->access->allows($user, 'contacts.view_all')) {
                $visible->orWhere('recipient_type', '!=', $contactType);
            }
        });

        $period = $filters['period'] ?? 'upcoming';

        if ($period === 'upcoming') {
            $query->where(function (Builder $upcoming): void {
                $upcoming->where('send_at', '>=', now())
                    ->orWhereIn('status', [
                        ScheduledMessage::STATUS_PENDING,
                        ScheduledMessage::STATUS_SENDING,
                    ]);
            });
        } elseif ($period === 'past') {
            $query->where('send_at', '<', now());
        }

        if (isset($filters['message_status'])) {
            if ($filters['message_status'] === 'held') {
                $query->where('status', ScheduledMessage::STATUS_PENDING)
                    ->where('operational_state', ScheduledMessage::OPERATIONAL_HELD);
            } elseif ($filters['message_status'] === 'pending') {
                $query->where('status', ScheduledMessage::STATUS_PENDING)
                    ->where('operational_state', ScheduledMessage::OPERATIONAL_ACTIVE);
            } else {
                $query->where('status', $filters['message_status']);
            }
        }

        if (isset($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (isset($filters['module'])) {
            $source = config('modules.outbound_message_sources.'.$filters['module'], []);
            $query->where(function (Builder $from) use ($source): void {
                foreach (['context', 'behavior_owner'] as $column) {
                    $types = $source[$column] ?? [];

                    if (is_array($types) && $types !== []) {
                        $from->orWhereIn($column.'_type', $types);
                    }
                }

                $origins = $source['origin'] ?? [];

                if (is_array($origins) && $origins !== []) {
                    $from->orWhereHas('messageChainEnrollment',
                        fn (Builder $enrollment) => $enrollment->whereIn('origin_type', $origins));
                }
            });
        }

        if (isset($filters['origin_type'], $filters['origin_id'])) {
            $query->whereHas('messageChainEnrollment', fn (Builder $enrollment) => $enrollment
                ->where('origin_type', $filters['origin_type'])
                ->where('origin_id', (int) $filters['origin_id']));
        }

        if (isset($filters['scope'], $filters['scope_id'])) {
            $scope = $filters['scope'];
            $id = (int) $filters['scope_id'];
            $types = config('modules.outbound_message_sources', []);

            if ($scope === 'webinar_series') {
                $origin = $types['webinars']['origin'][0] ?? null;
                $query->whereHas('messageChainEnrollment', fn (Builder $enrollment) => $enrollment
                    ->where('origin_type', $origin)
                    ->whereIn('origin_id', DB::table('webinars')
                        ->select('id')
                        ->where('webinar_series_id', $id)));
            } else {
                $module = $scope === 'webinar' ? 'webinars' : 'campaigns';
                $origin = $types[$module]['origin'][0] ?? null;
                $query->whereHas('messageChainEnrollment', fn (Builder $enrollment) => $enrollment
                    ->where('origin_type', $origin)
                    ->where('origin_id', $id));
            }
        }

        if (isset($filters['after'])) {
            $query->where('send_at', '>=', Carbon::parse(
                $filters['after'],
                (string) config('client.timezone', config('app.timezone', 'UTC')),
            )->startOfDay()->utc());
        }

        if (isset($filters['before'])) {
            $query->where('send_at', '<=', Carbon::parse(
                $filters['before'],
                (string) config('client.timezone', config('app.timezone', 'UTC')),
            )->endOfDay()->utc());
        }

        return $query;
    }

    public function visibleMessage(User $user, int $id): ScheduledMessage
    {
        return $this->query($user, ['period' => 'all'])->findOrFail($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(LengthAwarePaginator $messages, string $timezone): array
    {
        return $messages->getCollection()->map(function (ScheduledMessage $message) use ($timezone): array {
            $payload = is_array($message->payload) ? $message->payload : [];
            $template = $message->messageTemplateVersion?->payload() ?? [];
            $content = $this->contentEditor->supported($message)
                ? $this->contentEditor->fields($message)
                : [];
            $payload = array_replace($payload, $content);
            $recipient = $message->recipient;
            $name = $recipient?->name
                ?: (trim(($recipient?->first_name ?? '').' '.($recipient?->last_name ?? ''))
                    ?: ($recipient?->email ?? 'Recipient #'.$message->recipient_id));
            $body = $payload['body'] ?? $payload['message']
                ?? $template['body'] ?? $template['message'] ?? '';
            $status = $message->status === ScheduledMessage::STATUS_PENDING
                && $message->operational_state === ScheduledMessage::OPERATIONAL_HELD
                    ? 'held'
                    : $message->status;

            return [
                'id' => (int) $message->getKey(),
                'recipient' => (string) $name,
                'destination' => (string) ($payload['to'] ?? ''),
                'channel' => strtoupper((string) $message->channel),
                'type' => Str::headline((string) $message->message_type),
                'subject' => (string) ($payload['subject'] ?? $template['subject'] ?? ''),
                'preview' => is_string($body)
                    ? Str::limit(trim(strip_tags($body)), 120)
                    : '',
                'source' => $this->source($message),
                'status' => Str::headline((string) $status),
                'send_at' => $message->send_at?->timezone($timezone)->format('M j, Y g:i A'),
                'send_at_input' => $message->send_at?->timezone($timezone)->format('Y-m-d\\TH:i'),
                'can_edit' => $this->contentEditor->supported($message)
                    && $message->status === ScheduledMessage::STATUS_PENDING
                    && in_array($message->operational_state, [
                        ScheduledMessage::OPERATIONAL_ACTIVE,
                        ScheduledMessage::OPERATIONAL_HELD,
                    ], true),
                'edited' => $message->latestContentEdit !== null
                    && $message->latestContentEdit->override_payload !== [],
                'edit_fields' => $content,
                'can_hold' => $message->status === ScheduledMessage::STATUS_PENDING
                    && $message->operational_state === ScheduledMessage::OPERATIONAL_ACTIVE,
                'can_resume' => $message->status === ScheduledMessage::STATUS_PENDING
                    && $message->operational_state === ScheduledMessage::OPERATIONAL_HELD,
                'can_cancel' => $message->status === ScheduledMessage::STATUS_PENDING,
            ];
        })->all();
    }

    private function source(ScheduledMessage $message): string
    {
        $types = [
            $message->context_type,
            $message->behavior_owner_type,
            $message->messageChainEnrollment?->origin_type,
        ];

        foreach (config('modules.outbound_message_sources', []) as $module => $source) {
            if (array_intersect($types, array_merge(
                $source['context'] ?? [],
                $source['behavior_owner'] ?? [],
                $source['origin'] ?? [],
            )) !== []) {
                return Str::headline((string) $module);
            }
        }

        return 'Direct or other';
    }
}