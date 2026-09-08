<?php

namespace App\Modules\Broadcasts\Contacts;

use App\Modules\Broadcasts\Access\BroadcastsAccessCapabilityContributor;
use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;

final class BroadcastContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        yield new ContactResultAction(
            key: 'broadcasts.create',
            label: 'Send Broadcast',
            description: 'Open Broadcasts with this Contact result set already loaded as the audience.',
            view: 'crm.broadcasts.partials.contact-result-action',
            capability: BroadcastsAccessCapabilityContributor::CREATE_FROM_CONTACT_RESULTS,
            sort: 20,
        );
    }
}