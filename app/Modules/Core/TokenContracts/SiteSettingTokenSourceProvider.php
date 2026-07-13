<?php

namespace App\Modules\Core\TokenContracts;

use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenSourceDefinition;

class SiteSettingTokenSourceProvider implements TokenSourceProvider
{
    public function sources(): iterable
    {
        yield TokenSourceDefinition::computed(
            token: 'client_name',
            owner: 'core',
            label: 'Client name',
            description: 'Client display name resolved from site settings with client/app config fallback.',
            sourcePath: 'site_settings.client_name',
            providerClass: SiteSettingComputedTokenValueProvider::class,
            nullable: false,
        );

        yield TokenSourceDefinition::computed(
            token: 'client_signature',
            owner: 'core',
            label: 'Client signature',
            description: 'Message signature resolved from site settings with client name fallback.',
            sourcePath: 'site_settings.client_signature',
            providerClass: SiteSettingComputedTokenValueProvider::class,
            nullable: false,
        );
    }
}
