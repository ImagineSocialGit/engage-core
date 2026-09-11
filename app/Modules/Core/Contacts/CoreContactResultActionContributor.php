<?php

namespace App\Modules\Core\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;

final class CoreContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        $plural = str((string) config('contacts.labels.plural', 'contacts'))
            ->lower()
            ->toString();

        yield new ContactResultAction(
            key: 'core.add_tag',
            label: 'Add tag',
            description: 'Add one tag to every matching '.$plural.' you can manage.',
            view: 'crm.contacts.result-actions.tag',
            capability: 'contacts.manage',
            sort: 10,
            groupKey: 'edit',
            groupLabel: 'Edit '.$plural,
            groupDescription: 'Change shared properties for this result set without opening each record.',
            groupSort: 10,
        );

        yield new ContactResultAction(
            key: 'core.export',
            label: 'Export CSV',
            description: 'Download the visible '.$plural.' in this result set as CSV.',
            view: 'crm.contacts.result-actions.export',
            capability: 'contacts.export',
            sort: 10,
            groupKey: 'export',
            groupLabel: 'Export',
            groupDescription: 'Download this result set for reporting or migration work.',
            groupSort: 90,
        );
    }
}