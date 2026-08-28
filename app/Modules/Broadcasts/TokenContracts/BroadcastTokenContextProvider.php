<?php

namespace App\Modules\Broadcasts\TokenContracts;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;

class BroadcastTokenContextProvider implements TokenContextProvider
{
    public function contexts(): iterable
    {
        yield new TokenContextDefinition(
            key: Broadcast::DEFAULT_DISPATCH_KEY,
            owner: 'broadcasts',
            description: 'One-time Broadcast copy personalized independently for each Contact recipient.',
            sourceTokens: [
                'contact.first_name',
                'contact.last_name',
                'contact.name',
                'contact.email',
                'contact.phone',
                'contact.source',
                'contact.subsource',
            ],
            channels: ['email', 'sms'],
            purposes: ['marketing'],
            scopes: ['broadcast'],
            surfaces: ['broadcasts'],
        );
    }
}