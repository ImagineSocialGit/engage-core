<?php

namespace App\Modules\Messaging\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContactScheduledMessagesVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contactType = $contact->getMorphClass();

        $baseQuery = ScheduledMessage::query()
            ->where(function (Builder $query) use ($contact, $contactType): void {
                $query
                    ->where(function (Builder $query) use ($contact, $contactType): void {
                        $query->where('recipient_type', $contactType)
                            ->where('recipient_id', $contact->id);
                    })
                    ->orWhere(function (Builder $query) use ($contact, $contactType): void {
                        $query->where('context_type', $contactType)
                            ->where('context_id', $contact->id);
                    });
            });

        $pendingMessages = (clone $baseQuery)
            ->where('status', 'pending')
            ->oldest('send_at')
            ->limit(10)
            ->get();

        $recentMessages = (clone $baseQuery)
            ->whereIn('status', ['sent', 'failed', 'skipped'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $messages = $pendingMessages
            ->concat($recentMessages)
            ->unique('id')
            ->values();

        return [
            'contactVisibilitySections' => [
                'scheduled_messages' => [
                    'title' => 'Messages already handled',
                    'module' => 'messaging',
                    'description' => 'Upcoming messages and recent outbound delivery records.',
                    'empty' => 'No scheduled messages or recent delivery records found.',
                    'preview_count' => 1,
                    'items' => $this->items($messages),
                ],
            ],
        ];
    }

    /**
     * @param Collection<int, ScheduledMessage> $messages
     * @return array<int, array<string, mixed>>
     */
    private function items(Collection $messages): array
    {
        return $messages
            ->map(fn (ScheduledMessage $message): array => [
                'title' => $this->label($message->message_type) ?? 'Scheduled Message',
                'subtitle' => trim(implode(' / ', array_filter([
                    $this->label($message->channel),
                    $this->label($message->purpose),
                    $this->label($message->scope),
                ]))),
                'status' => $this->label($message->status),
                'meta' => $this->meta($message),
            ])
            ->all();
    }

    private function label(?string $value): ?string
    {
        return filled($value)
            ? Str::of($value)->replace('_', ' ')->title()->toString()
            : null;
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A');
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(ScheduledMessage $message): array
    {
        $meta = [
            'Message ID' => '#'.$message->id,
            'Queue' => $this->label(data_get($message->meta, 'queue')),
        ];

        return match ($message->status) {
            ScheduledMessage::STATUS_PENDING => array_merge($meta, [
                'Send At' => $this->date($message->send_at),
            ]),

            ScheduledMessage::STATUS_SENT => array_merge($meta, [
                'Sent At' => $this->date($message->sent_at),
            ]),

            ScheduledMessage::STATUS_SKIPPED => array_merge($meta, [
                'Skipped At' => $this->date($message->skipped_at),
                'Reason' => $message->skip_reason,
            ]),

            ScheduledMessage::STATUS_FAILED => array_merge($meta, [
                'Failed At' => $this->date($message->failed_at),
                'Failure' => $message->failure_reason,
            ]),

            default => array_merge($meta, [
                'Send At' => $this->date($message->send_at),
                'Updated' => $this->date($message->updated_at),
            ]),
        };
    }
}
