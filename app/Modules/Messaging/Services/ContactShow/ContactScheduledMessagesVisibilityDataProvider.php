<?php

namespace App\Modules\Messaging\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Str;

class ContactScheduledMessagesVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contactType = $contact->getMorphClass();

        $messages = ScheduledMessage::query()
            ->where(function ($query) use ($contact, $contactType) {
                $query
                    ->where(function ($query) use ($contact, $contactType) {
                        $query->where('recipient_type', $contactType)
                            ->where('recipient_id', $contact->id);
                    })
                    ->orWhere(function ($query) use ($contact, $contactType) {
                        $query->where('context_type', $contactType)
                            ->where('context_id', $contact->id);
                    });
            })
            ->orderByRaw("FIELD(status, 'pending', 'failed', 'sent', 'skipped')")
            ->latest('send_at')
            ->limit(8)
            ->get();

        return [
            'contactVisibilitySections' => [
                'scheduled_messages' => [
                    'title' => 'Scheduled Messages',
                    'description' => 'Pending and recent outbound delivery records.',
                    'empty' => 'No scheduled messages found.',
                    'items' => $messages->map(fn (ScheduledMessage $message): array => [
                        'title' => $this->label($message->message_type) ?? 'Scheduled Message',
                        'subtitle' => trim(implode(' / ', array_filter([
                            $this->label($message->channel),
                            $this->label($message->purpose),
                            $this->label($message->scope),
                        ]))),
                        'status' => $this->label($message->status),
                        'meta' => [
                            'Send At' => $this->date($message->send_at),
                            'Sent At' => $this->date($message->sent_at),
                            'Skipped At' => $this->date($message->skipped_at),
                            'Failed At' => $this->date($message->failed_at),
                            'Failure' => $message->failure_reason,
                            'Queue' => $this->label(data_get($message->meta, 'queue')),
                        ],
                    ])->all(),
                ],
            ],
        ];
    }

    private function label(?string $value): ?string
    {
        return filled($value)
            ? Str::of($value)->replace('_', ' ')->title()->toString()
            : null;
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }
}