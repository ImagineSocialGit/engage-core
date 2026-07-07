<?php

namespace App\Modules\InboundMessaging\Services\Dashboard;

use App\Models\DashboardAcknowledgement;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadRepliesDashboardPanelProvider implements DashboardPanelProvider
{
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
        $acknowledgedIds = $this->acknowledgedItemKeys($request, DashboardAcknowledgement::TYPE_INBOUND_MESSAGE);

        $baseQuery = InboundMessage::query()
            ->with('sender')
            ->where('classification', InboundMessage::CLASSIFICATION_NORMAL_REPLY)
            ->where('received_at', '>=', now()->subDays(3))
            ->when($acknowledgedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $acknowledgedIds));

        $recentCount = (clone $baseQuery)->count();

        $replies = (clone $baseQuery)
            ->latest('received_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'immediate_work',
            'priority' => $recentCount > 0 ? 110 : 90,
            'order' => 20,
            'view' => 'lead_replies',
            'title' => str(config('contacts.labels.plural'))->title().' needing attention',
            'description' => 'Recent replies that may need a human response.',
            'empty_title' => 'No '.config('contacts.labels.singular').' replies need review.',
            'empty_description' => 'New replies will show here when a '.config('contacts.labels.singular').' needs a human response.',
            'summary_label' => config('contacts.labels.singular').' replies',
            'count' => $recentCount,
            'attention_count' => $recentCount,
            'items' => $replies->map(fn (InboundMessage $message): array => $this->leadReplyItem($message))->values(),
            'primary_action' => $this->primaryAction($replies->first()),
            'actions' => [
                [
                    'label' => 'View all '.config('contacts.labels.plural'),
                    'href' => route('crm.contacts.index'),
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

        $contact = $message->sender instanceof Contact ? $message->sender : null;

        return [
            'label' => $contact ? 'Review reply' : 'Review message',
            'href' => $contact
                ? route('crm.contacts.show', $contact).'?activity_tab=messages&focus=inbound_message:'.$message->id
                : null,
            'summary' => 'A recent reply is the clearest '.config('contacts.labels.singular').' to review first.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadReplyItem(InboundMessage $message): array
    {
        $contact = $message->sender instanceof Contact ? $message->sender : null;
        $sender = $contact ? $this->contactName($contact) : ($message->from_value ?: 'Unknown sender');

        return [
            'key' => (string) $message->id,
            'type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
            'sort_at' => $message->received_at ?? $message->created_at,
            'label' => 'New reply',
            'tone' => 'blue',
            'title' => $sender.' replied',
            'subtitle' => trim(implode(' · ', array_filter([
                strtoupper($this->enumValue($message->channel)),
                $this->dateLabel($message->received_at),
            ]))),
            'description' => $message->body,
            'href' => $contact
                ? route('crm.contacts.show', $contact).'?activity_tab=messages&focus=inbound_message:'.$message->id
                : null,
            'action_label' => $contact ? 'Review reply' : 'Review message',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function acknowledgedItemKeys(Request $request, string $itemType): array
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            return [];
        }

        return DashboardAcknowledgement::query()
            ->active()
            ->where('user_id', $userId)
            ->where('surface', DashboardAcknowledgement::SURFACE_CRM_DASHBOARD)
            ->where('item_type', DashboardAcknowledgement::normalizeItemType($itemType))
            ->pluck('item_key')
            ->values()
            ->all();
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date?->copy()
            ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, g:i A');
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== '' ? $name : ($contact->email ?: Str::title(config('contacts.labels.singular')).' #'.$contact->id);
    }
}
