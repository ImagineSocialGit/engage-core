<?php

namespace App\Modules\Broadcasts\Contacts;

use App\Modules\Broadcasts\Access\BroadcastsAccessCapabilityContributor;
use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;

final class BroadcastContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        $plural = str((string) config('contacts.labels.plural', 'contacts'))
            ->lower()
            ->toString();

        yield new ContactResultAction(
            key: 'broadcasts.create',
            label: 'Send broadcast',
            description: 'Open Broadcasts with this '.$plural.' result set already loaded as the audience.',
            view: 'crm.broadcasts.partials.contact-result-action',
            capability: BroadcastsAccessCapabilityContributor::CREATE_FROM_CONTACT_RESULTS,
            sort: 10,
            groupKey: 'messaging',
            groupLabel: 'Messaging',
            groupDescription: 'Send or enroll this result set using the messaging tools available to this client.',
            groupSort: 20,
        );
    }
}