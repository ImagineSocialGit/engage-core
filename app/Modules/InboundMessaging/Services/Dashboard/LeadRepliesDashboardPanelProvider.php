<?php

namespace App\Modules\InboundMessaging\Services\Dashboard;

use App\Models\DashboardAcknowledgement;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Inbox\InboundInboxWorkspace;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class LeadRepliesDashboardPanelProvider implements DashboardPanelProvider
{
    public function __construct(
        private readonly InboundInboxWorkspace $inboxWorkspace,
    ) {}

    public function key(): string
    {
        return 'inbound_messaging.replies';
    }

    public function module(): string
    {
        return 'inbound_messaging';
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(Request $request): array
    {
        $acknowledgedIds = $this->acknowledgedItemKeys(
            $request,
            DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
        );

        $baseQuery = InboundMessage::query()
            ->with([
                'sender',
                'relatedContact',
                'correlatedScheduledMessage.messageChainStepVariant',
            ])
            ->whereIn('inbox_status', [
                InboundMessage::INBOX_STATUS_NEW,
                InboundMessage::INBOX_STATUS_REVIEWED,
            ])
            ->when(
                $acknowledgedIds !== [],
                fn (Builder $query): Builder =>
                    $query->whereNotIn('id', $acknowledgedIds),
            );

        $openCount = (clone $baseQuery)->count();

        $messages = (clone $baseQuery)
            ->latest('received_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'immediate_work',
            'priority' => $openCount > 0 ? 110 : 90,
            'order' => 20,
            'view' => 'lead_replies',
            'title' => 'Inbox needing attention',
            'description' => 'Inbound messages that still need a human review or follow-up.',
            'empty_title' => 'Inbox is caught up.',
            'empty_description' => 'New replies and routed inbound messages will appear here.',
            'summary_label' => 'inbox messages',
            'count' => $openCount,
            'attention_count' => $openCount,
            'items' => $messages
                ->map(fn (InboundMessage $message): array =>
                    $this->dashboardItem($message)
                )
                ->values(),
            'primary_action' => $this->primaryAction($messages->first()),
            'actions' => [
                [
                    'label' => 'Open Inbox',
                    'href' => route('crm.inbound-messaging.inbox.index'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function primaryAction(?InboundMessage $message): ?array
    {
        if (! $message) {
            return null;
        }

        return [
            'label' => 'Review message',
            'href' => route('crm.inbound-messaging.inbox.show', $message),
            'summary' => 'Inbound work stays available in the Inbox until it is done.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardItem(InboundMessage $message): array
    {
        $item = $this->inboxWorkspace->presentMessage($message);
        $person = $item['person'] ?? null;
        $sender = (string) ($item['sender_label'] ?? 'Unknown sender');

        return [
            'key' => (string) $message->getKey(),
            'type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
            'sort_at' => $message->received_at ?? $message->created_at,
            'label' => (string) ($item['status_label'] ?? 'Needs review'),
            'tone' => $message->inbox_status === InboundMessage::INBOX_STATUS_REVIEWED
                ? 'slate'
                : 'blue',
            'title' => $person instanceof Contact
                ? (string) $item['person_label'].' replied'
                : ((string) ($item['subject'] ?? '') !== ''
                    ? (string) $item['subject']
                    : $sender.' sent a message'),
            'subtitle' => trim(implode(' · ', array_filter([
                $person instanceof Contact ? null : $sender,
                $item['received_through'] ?? null,
                $item['received_at_label'] ?? null,
            ]))),
            'description' => (string) ($item['preview'] ?? ''),
            'href' => route('crm.inbound-messaging.inbox.show', $message),
            'action_label' => 'Review message',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function acknowledgedItemKeys(
        Request $request,
        string $itemType,
    ): array {
        $userId = $request->user()?->id;

        if (! $userId) {
            return [];
        }

        return DashboardAcknowledgement::query()
            ->active()
            ->where('user_id', $userId)
            ->where('surface', DashboardAcknowledgement::SURFACE_CRM_DASHBOARD)
            ->where(
                'item_type',
                DashboardAcknowledgement::normalizeItemType($itemType),
            )
            ->pluck('item_key')
            ->values()
            ->all();
    }
}