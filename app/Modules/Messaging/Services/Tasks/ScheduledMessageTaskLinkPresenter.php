<?php

namespace App\Modules\Messaging\Services\Tasks;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ScheduledMessageSummary;
use App\Modules\Tasks\Contracts\TaskLinkPresenterContract;
use Illuminate\Database\Eloquent\Model;

class ScheduledMessageTaskLinkPresenter implements TaskLinkPresenterContract
{
    public function __construct(
        private readonly ScheduledMessageSummary $summary,
    ) {}

    public function supports(Model $linkable): bool
    {
        return $linkable instanceof ScheduledMessage;
    }

    /** @return array<string, mixed> */
    public function present(Model $linkable): array
    {
        $summary = $this->summary->present($linkable);

        return [
            'record' => $linkable,
            'type' => $linkable->getMorphClass(),
            'kind' => 'scheduled_message',
            'label' => 'Outbound message',
            'name' => $summary['name'],
            'scheduled_message_id' => $summary['scheduled_message_id'],
            'conversation_label' => $summary['conversation_label'],
            'template_name' => $summary['template_name'],
            'template_url' => $summary['template_url'],
            'subject' => $summary['subject'],
            'url' => $summary['url'],
            'details' => array_filter([
                'Channel' => $summary['channel'],
                'Sent' => $summary['occurred_at_label'],
                'Status' => $summary['status'],
            ], fn (mixed $value): bool => filled($value)),
            'message' => $summary['message'],
            'channel' => $summary['channel'],
            'occurred_at' => $summary['occurred_at'],
            'occurred_at_label' => $summary['occurred_at_label'],
            'status' => $summary['status'],
            'reply_to' => null,
        ];
    }
}