<?php

namespace App\Modules\Messaging\Services\ContactPanels;

use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Services\ContactDirectMessageComposerPresenter;

final class ContactDirectMessagePanelProvider implements ContactPanelProvider
{
    public function __construct(
        private readonly ContactDirectMessageComposerPresenter $composer,
    ) {}

    public function panels(Contact $contact): array
    {
        return [
            new ContactPanel(
                key: 'messaging-direct-message',
                title: 'Messaging',
                view: 'crm.contacts.panels.messaging-direct-message',
                data: [
                    'directMessageComposer' => $this->composer->forContact($contact),
                ],
                sort: 5,
                module: 'messaging',
            ),
        ];
    }
}