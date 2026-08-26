<?php

namespace App\Modules\InboundMessaging\Services\Inbox;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\Messaging\Models\ScheduledMessage;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class InboundInboxWorkspace
{
    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request);
        $routeLabels = $this->routeLabels();
        $profileLabels = $this->replyProfileLabels();

        $query = $this->messageQuery();

        $this->applyStatusFilter($query, $filters['status']);
        $this->applyPersonFilter($query, $filters['person']);
        $this->applyThroughFilter($query, $filters['through']);
        $this->applySearch($query, $filters['search']);

        /** @var LengthAwarePaginator $messages */
        $messages = $query
            ->latest('received_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $messages->setCollection(
            $messages->getCollection()
                ->map(fn (InboundMessage $message): array => $this->present(
                    $message,
                    $routeLabels,
                    $profileLabels,
                )),
        );

        return [
            'messages' => $messages,
            'filters' => $filters,
            'counts' => $this->counts(),
            'through_options' => $this->throughOptions(
                $routeLabels,
                $profileLabels,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(
        Request $request,
        InboundMessage $message,
    ): array {
        $message->loadMissing([
            'sender',
            'relatedContact',
            'correlatedScheduledMessage.messageChainStepVariant',
        ]);

        $routeLabels = $this->routeLabels();
        $profileLabels = $this->replyProfileLabels();
        $person = $this->person($message);

        return [
            'message' => $message,
            'presentation' => $this->present(
                $message,
                $routeLabels,
                $profileLabels,
            ),
            'person' => $person,
            'person_is_manual_link' => $message->relatedContact instanceof Contact,
            'contact_search' => $this->contactSearch($request),
            'create_defaults' => [
                'name' => '',
                'email' => $this->suggestedEmail($message),
                'phone' => $this->suggestedPhone($message),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMessage(InboundMessage $message): array
    {
        $message->loadMissing([
            'sender',
            'relatedContact',
            'correlatedScheduledMessage.messageChainStepVariant',
        ]);

        return $this->present(
            $message,
            $this->routeLabels(),
            $this->replyProfileLabels(),
        );
    }

    /**
     * @return Builder<InboundMessage>
     */
    private function messageQuery(): Builder
    {
        return InboundMessage::query()->with([
            'sender',
            'relatedContact',
            'correlatedScheduledMessage.messageChainStepVariant',
        ]);
    }

    /**
     * @return array{status: string, person: string, through: string, search: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status', InboundMessage::INBOX_STATUS_NEW);
        $person = $request->query('person', 'all');
        $through = $request->query('through', 'all');
        $search = $request->query('search', '');

        return [
            'status' => is_string($status)
                && in_array($status, [
                    'all',
                    InboundMessage::INBOX_STATUS_NEW,
                    InboundMessage::INBOX_STATUS_REVIEWED,
                    InboundMessage::INBOX_STATUS_DONE,
                ], true)
                    ? $status
                    : InboundMessage::INBOX_STATUS_NEW,
            'person' => is_string($person)
                && in_array($person, ['all', 'matched', 'unmatched'], true)
                    ? $person
                    : 'all',
            'through' => is_string($through) && mb_strlen($through) <= 220
                ? trim($through)
                : 'all',
            'search' => is_string($search)
                ? mb_substr(trim($search), 0, 255)
                : '',
        ];
    }

    /**
     * @param Builder<InboundMessage> $query
     */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status !== 'all') {
            $query->where('inbox_status', $status);
        }
    }

    /**
     * @param Builder<InboundMessage> $query
     */
    private function applyPersonFilter(Builder $query, string $person): void
    {
        if ($person === 'all') {
            return;
        }

        $contactTypes = array_values(array_unique([
            Contact::class,
            (new Contact())->getMorphClass(),
        ]));

        if ($person === 'matched') {
            $query->where(function (Builder $matched) use ($contactTypes): void {
                $matched
                    ->whereNotNull('related_contact_id')
                    ->orWhere(function (Builder $sender) use ($contactTypes): void {
                        $sender
                            ->whereIn('sender_type', $contactTypes)
                            ->whereNotNull('sender_id');
                    });
            });

            return;
        }

        $query
            ->whereNull('related_contact_id')
            ->where(function (Builder $sender) use ($contactTypes): void {
                $sender
                    ->whereNull('sender_id')
                    ->orWhereNull('sender_type')
                    ->orWhereNotIn('sender_type', $contactTypes);
            });
    }

    /**
     * @param Builder<InboundMessage> $query
     */
    private function applyThroughFilter(
        Builder $query,
        string $through,
    ): void {
        if ($through === '' || $through === 'all') {
            return;
        }

        if (str_starts_with($through, 'route:')) {
            $key = substr($through, 6);

            if ($key !== '') {
                $query->where('inbound_email_route_key', $key);
            }

            return;
        }

        if (str_starts_with($through, 'reply:')) {
            $key = substr($through, 6);

            if ($key === '') {
                return;
            }

            $query->whereHas(
                'correlatedScheduledMessage',
                function (Builder $scheduled) use ($key): void {
                    $scheduled->where(function (Builder $reply) use ($key): void {
                        $reply
                            ->where('reply_profile_key', $key)
                            ->orWhereHas(
                                'messageChainStepVariant',
                                fn (Builder $variant): Builder =>
                                    $variant->where('reply_profile_key', $key),
                            );
                    });
                },
            );

            return;
        }

        if (str_starts_with($through, 'channel:')) {
            $channel = substr($through, 8);

            if (in_array($channel, ['email', 'sms'], true)) {
                $query->where('channel', $channel);
            }
        }
    }

    /**
     * @param Builder<InboundMessage> $query
     */
    private function applySearch(
        Builder $query,
        string $search,
    ): void {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $matches) use ($like): void {
            $matches
                ->where('subject', 'like', $like)
                ->orWhere('body', 'like', $like)
                ->orWhere('from_value', 'like', $like)
                ->orWhere('to_value', 'like', $like)
                ->orWhereHas(
                    'relatedContact',
                    fn (Builder $contact): Builder => $this->searchContact(
                        $contact,
                        $like,
                    ),
                )
                ->orWhereHasMorph(
                    'sender',
                    [Contact::class],
                    fn (Builder $contact): Builder => $this->searchContact(
                        $contact,
                        $like,
                    ),
                );
        });
    }

    private function searchContact(
        Builder $query,
        string $like,
    ): Builder {
        return $query->where(function (Builder $contact) use ($like): void {
            $contact
                ->where('name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = InboundMessage::query()
            ->selectRaw('inbox_status, COUNT(*) AS aggregate')
            ->groupBy('inbox_status')
            ->pluck('aggregate', 'inbox_status');

        return [
            'all' => (int) $counts->sum(),
            'new' => (int) ($counts[InboundMessage::INBOX_STATUS_NEW] ?? 0),
            'reviewed' => (int) ($counts[InboundMessage::INBOX_STATUS_REVIEWED] ?? 0),
            'done' => (int) ($counts[InboundMessage::INBOX_STATUS_DONE] ?? 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function routeLabels(): array
    {
        return InboundEmailRoute::query()
            ->orderBy('label')
            ->pluck('label', 'key')
            ->mapWithKeys(
                fn (mixed $label, mixed $key): array => [
                    (string) $key => trim((string) $label),
                ],
            )
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function replyProfileLabels(): array
    {
        return InboundReplyProfile::withTrashed()
            ->orderBy('label')
            ->pluck('label', 'key')
            ->mapWithKeys(
                fn (mixed $label, mixed $key): array => [
                    (string) $key => trim((string) $label),
                ],
            )
            ->all();
    }

    /**
     * @param array<string, string> $routeLabels
     * @param array<string, string> $profileLabels
     * @return array<int, array{value: string, label: string}>
     */
    private function throughOptions(
        array $routeLabels,
        array $profileLabels,
    ): array {
        $options = [];

        foreach ($routeLabels as $key => $label) {
            $options[] = [
                'value' => 'route:'.$key,
                'label' => $label,
            ];
        }

        foreach ($profileLabels as $key => $label) {
            $options[] = [
                'value' => 'reply:'.$key,
                'label' => $label,
            ];
        }

        $options[] = [
            'value' => 'channel:email',
            'label' => 'Email',
        ];
        $options[] = [
            'value' => 'channel:sms',
            'label' => 'Text messages',
        ];

        return $options;
    }

    /**
     * @param array<string, string> $routeLabels
     * @param array<string, string> $profileLabels
     * @return array<string, mixed>
     */
    private function present(
        InboundMessage $message,
        array $routeLabels,
        array $profileLabels,
    ): array {
        $person = $this->person($message);
        $body = trim((string) $message->body);

        return [
            'message' => $message,
            'sender_label' => $this->senderLabel($message),
            'person' => $person,
            'person_label' => $person instanceof Contact
                ? $this->contactLabel($person)
                : 'Not matched to a person',
            'received_through' => $this->receivedThroughLabel(
                $message,
                $routeLabels,
                $profileLabels,
            ),
            'channel_label' => $this->channelLabel($message->channel),
            'status_label' => $this->statusLabel($message->inbox_status),
            'status' => $message->inbox_status,
            'received_at_label' => $this->dateLabel(
                $message->received_at ?? $message->created_at,
            ),
            'subject' => filled($message->subject)
                ? trim((string) $message->subject)
                : null,
            'preview' => $body !== ''
                ? Str::limit(preg_replace('/\s+/u', ' ', $body) ?? $body, 180)
                : 'No message text was provided.',
            'href' => route('crm.inbound-messaging.inbox.show', $message),
        ];
    }

    private function person(InboundMessage $message): ?Contact
    {
        if ($message->relatedContact instanceof Contact) {
            return $message->relatedContact;
        }

        return $message->sender instanceof Contact
            ? $message->sender
            : null;
    }

    private function senderLabel(InboundMessage $message): string
    {
        if ($message->sender instanceof Contact) {
            return $this->contactLabel($message->sender);
        }

        $from = trim((string) $message->from_value);

        return $from !== '' ? $from : 'Unknown sender';
    }

    /**
     * @param array<string, string> $routeLabels
     * @param array<string, string> $profileLabels
     */
    private function receivedThroughLabel(
        InboundMessage $message,
        array $routeLabels,
        array $profileLabels,
    ): string {
        $routeKey = trim((string) $message->inbound_email_route_key);

        if ($routeKey !== '' && isset($routeLabels[$routeKey])) {
            return $routeLabels[$routeKey];
        }

        $scheduled = $message->correlatedScheduledMessage;
        $profileKey = $scheduled instanceof ScheduledMessage
            ? $scheduled->replyProfileKey()
            : null;

        if (is_string($profileKey)
            && $profileKey !== ''
            && isset($profileLabels[$profileKey])
        ) {
            return $profileLabels[$profileKey];
        }

        return $this->channelValue($message->channel) === 'sms'
            ? 'Text messages'
            : 'Email';
    }

    private function channelLabel(mixed $channel): string
    {
        return $this->channelValue($channel) === 'sms'
            ? 'Text'
            : 'Email';
    }

    private function channelValue(mixed $channel): string
    {
        return $channel instanceof BackedEnum
            ? (string) $channel->value
            : trim((string) $channel);
    }

    private function statusLabel(mixed $status): string
    {
        return match ((string) $status) {
            InboundMessage::INBOX_STATUS_REVIEWED => 'In progress',
            InboundMessage::INBOX_STATUS_DONE => 'Done',
            default => 'Needs review',
        };
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date?->copy()
            ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, Y g:i A');
    }

    private function contactLabel(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== ''
            ? $name
            : ($contact->email ?: $contact->phone ?: Str::title(
                config('contacts.labels.singular'),
            ));
    }

    /**
     * @return Collection<int, array{contact: Contact, label: string}>
     */
    private function contactSearch(Request $request): Collection
    {
        $search = $request->query('contact_search');

        if (! is_string($search) || mb_strlen(trim($search)) < 2) {
            return collect();
        }

        $search = mb_substr(trim($search), 0, 255);
        $like = '%'.$search.'%';

        return Contact::query()
            ->where(function (Builder $query) use ($like): void {
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('email')
            ->limit(12)
            ->get()
            ->map(fn (Contact $contact): array => [
                'contact' => $contact,
                'label' => trim(implode(' · ', array_filter([
                    $this->contactLabel($contact),
                    $contact->email,
                    $contact->phone,
                ]))),
            ]);
    }

    private function suggestedEmail(InboundMessage $message): string
    {
        if ($message->sender instanceof Contact
            || filled($message->inbound_email_route_key)
            || $message->from_type !== 'email'
        ) {
            return '';
        }

        $email = trim((string) $message->from_value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : '';
    }

    private function suggestedPhone(InboundMessage $message): string
    {
        if ($message->sender instanceof Contact
            || $message->from_type !== 'phone'
        ) {
            return '';
        }

        return trim((string) $message->from_value);
    }
}