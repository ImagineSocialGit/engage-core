<?php

namespace App\Modules\Messaging\Services\ContactPanels;

use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Services\DeliveryIssues\MessageDeliveryIssueReviewService;

final class MessageDeliveryIssueContactPanelProvider implements ContactPanelProvider
{
    public function __construct(
        private readonly MessageDeliveryIssueReviewService $issues,
    ) {}

    public function panels(Contact $contact): array
    {
        $suppressions = $this->issues->forContact($contact);

        if ($suppressions->isEmpty()) {
            return [];
        }

        return [
            new ContactPanel(
                key: 'messaging-delivery-issues',
                title: 'Messaging Delivery Issues',
                view: 'crm.contacts.panels.messaging-delivery-issues',
                data: [
                    'deliveryIssues' => $this->issues->present($suppressions),
                ],
                sort: 20,
                module: 'messaging',
            ),
        ];
    }
}