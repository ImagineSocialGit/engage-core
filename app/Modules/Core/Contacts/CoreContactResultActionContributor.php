<?php

namespace App\Modules\Core\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;

final class CoreContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        yield new ContactResultAction(
            key: 'core.add_tag',
            label: 'Add tag',
            description: 'Add one tag to every Contact in this result set that you can manage.',
            view: 'crm.contacts.result-actions.tag',
            capability: 'contacts.manage',
            sort: 10,
        );

        yield new ContactResultAction(
            key: 'core.export',
            label: 'Export',
            description: 'Download the visible Contacts in this result set as CSV.',
            view: 'crm.contacts.result-actions.export',
            capability: 'contacts.export',
            sort: 40,
        );
    }
}