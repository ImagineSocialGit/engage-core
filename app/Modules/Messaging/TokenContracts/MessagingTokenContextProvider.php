<?php

namespace App\Modules\Messaging\TokenContracts;

use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;

class MessagingTokenContextProvider implements TokenContextProvider
{
    private const CONTACT_COPY_SOURCES = [
        'contact.first_name',
        'contact.last_name',
        'contact.name',
        'contact.email',
        'contact.phone',
    ];

    private const CLIENT_IDENTITY_SOURCES = [
        'client_name',
        'client_signature',
    ];

    public function contexts(): iterable
    {
        yield new TokenContextDefinition(
            key: 'consent_granted',
            owner: 'messaging',
            description: 'Copy rendered after a contact grants message consent.',
            sourceTokens: [
                ...self::CONTACT_COPY_SOURCES,
                ...self::CLIENT_IDENTITY_SOURCES,
            ],
            channels: ['email', 'sms'],
            purposes: ['transactional', 'marketing'],
        );

        yield new TokenContextDefinition(
            key: 'imported_contact_permission_invitation',
            owner: 'messaging',
            description: 'One-time imported-contact permission invitation copy.',
            sourceTokens: [
                ...self::CONTACT_COPY_SOURCES,
                ...self::CLIENT_IDENTITY_SOURCES,
            ],
            channels: ['email'],
            purposes: ['transactional'],
            scopes: ['permission_invitation'],
            surfaces: ['permission_invitations'],
        );
    }
}
