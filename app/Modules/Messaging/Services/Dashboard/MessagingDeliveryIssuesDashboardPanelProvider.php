<?php

namespace App\Modules\Messaging\Services\Dashboard;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\DeliveryIssues\MessageDeliveryIssueReviewService;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MessagingDeliveryIssuesDashboardPanelProvider implements DashboardPanelProvider
{
    public function __construct(
        private readonly MessageDeliveryIssueReviewService $issues,
    ) {}

    public function key(): string
    {
        return 'messaging.delivery_issues';
    }

    public function module(): string
    {
        return 'messaging';
    }

    public function panel(Request $request): ?array
    {
        $count = $this->issues->query()->count();

        if ($count === 0) {
            return null;
        }

        $suppressions = $this->issues->query()
            ->limit(6)
            ->get();

        $items = $this->issues->present($suppressions)
            ->map(function (array $issue): array {
                /** @var MessageSuppression $suppression */
                $suppression = $issue['suppression'];
                /** @var Contact|null $contact */
                $contact = $issue['contacts']->first();

                return [
                    'key' => (string) $suppression->getKey(),
                    'label' => $issue['reason_label'],
                    'title' => $contact?->name
                        ?: $contact?->email
                        ?: $suppression->destination,
                    'subtitle' => Str::headline((string) $suppression->channel)
                        .' · '.$suppression->destination,
                    'description' => filled($suppression->provider)
                        ? 'Reported by '.Str::headline((string) $suppression->provider).'.'
                        : null,
                    'href' => $contact
                        ? route('crm.contacts.show', $contact)
                        : route('crm.messaging.delivery-issues.index'),
                    'action_label' => 'Review',
                    'tone' => 'amber',
                ];
            })
            ->values();

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'immediate_work',
            'priority' => 140,
            'order' => 0,
            'view' => 'list',
            'title' => 'Messaging delivery issues',
            'description' => 'Bounces and suppressed destinations that still match current Contact information.',
            'summary_label' => 'delivery issues',
            'count' => $count,
            'attention_count' => $count,
            'hide_when_empty' => true,
            'items' => $items,
            'primary_action' => [
                'label' => 'Review delivery issues',
                'href' => route('crm.messaging.delivery-issues.index'),
                'summary' => $count.' '.Str::plural('messaging destination', $count).' need review.',
            ],
            'actions' => [
                [
                    'label' => 'Review all',
                    'href' => route('crm.messaging.delivery-issues.index'),
                ],
            ],
        ];
    }
}