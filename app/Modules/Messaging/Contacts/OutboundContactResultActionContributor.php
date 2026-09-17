<?php

namespace App\Modules\Messaging\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;

final class OutboundContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        yield new ContactResultAction(
            key: 'messaging.outbound.contact_group',
            label: 'Scheduled messages for this group',
            description: 'Review the scheduled messages for all visible contacts matching the current filters.',
            view: 'crm.messaging.outbound.contact-result-action',
            capability: 'contacts.manage',
            sort: 5,
            groupKey: 'messaging',
            groupLabel: 'Messaging',
            groupDescription: 'Send or enroll this result set using the messaging tools available to this client.',
            groupSort: 20,
        );
    }
}