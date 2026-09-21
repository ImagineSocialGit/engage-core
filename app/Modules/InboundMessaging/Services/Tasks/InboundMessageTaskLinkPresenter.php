<?php

namespace App\Modules\InboundMessaging\Services\Tasks;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Services\ScheduledMessageSummary;
use App\Modules\Tasks\Contracts\TaskLinkPresenterContract;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InboundMessageTaskLinkPresenter implements TaskLinkPresenterContract
{
    public function __construct(
        private readonly ScheduledMessageSummary $scheduledMessages,
    ) {}

    public function supports(Model $linkable): bool
    {
        return $linkable instanceof InboundMessage;
    }

    /** @return array<string, mixed> */
    public function present(Model $linkable): array
    {
        $linkable->loadMissing('correlatedScheduledMessage');

        $channel = $linkable->channel instanceof BackedEnum
            ? $linkable->channel->value
            : (string) $linkable->channel;
        $channelLabel = $channel !== '' ? Str::headline($channel) : 'Message';
        $receivedAt = $linkable->received_at?->timezone(
            config('client.timezone', config('app.timezone', 'UTC')),
        );
        $replyTo = $linkable->correlatedScheduledMessage
            ? $this->scheduledMessages->present($linkable->correlatedScheduledMessage)
            : null;

        return [
            'record' => $linkable,
            'type' => $linkable->getMorphClass(),
            'kind' => 'inbound_message',
            'label' => 'Inbound reply',
            'name' => filled($linkable->subject)
                ? trim((string) $linkable->subject)
                : $channelLabel.' reply',
            'url' => route('crm.inbound-messaging.inbox.show', $linkable),
            'details' => array_filter([
                'Channel' => $channelLabel,
                'Received' => $receivedAt?->format('M j, Y g:i A T'),
                'From' => $linkable->from_value,
            ], fn (mixed $value): bool => filled($value)),
            'message' => trim((string) $linkable->body),
            'channel' => $channelLabel,
            'occurred_at' => $linkable->received_at?->toIso8601String(),
            'reply_to' => $replyTo,
        ];
    }
}