<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Contracts\PortalNavigationProvider;
use App\Modules\Portal\Data\PortalNavigationItem;
use App\Modules\Portal\Models\PortalUser;
use LogicException;

final class PortalNavigationRegistry
{
    public const TAG = 'portal.navigation_providers';

    public function __construct(private readonly iterable $providers) {}

    /** @return array<int, PortalNavigationItem> */
    public function forUser(PortalUser $user): array
    {
        $definitions = [
            'home' => new PortalNavigationItem('home', 'Home', 'portal.home', sort: 0, requiresVerifiedEmail: false),
            'account' => new PortalNavigationItem('account', 'Account', 'portal.account.show', sort: 900, requiresVerifiedEmail: false),
        ];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof PortalNavigationProvider) {
                continue;
            }

            foreach ($provider->definitions($user) as $definition) {
                if (! $definition instanceof PortalNavigationItem) {
                    throw new LogicException('Portal navigation providers must yield PortalNavigationItem instances.');
                }

                if ($definition->requiresVerifiedEmail && ! $user->hasVerifiedEmail()) {
                    continue;
                }

                if (array_key_exists($definition->key, $definitions)) {
                    throw new LogicException("Duplicate Portal navigation key [{$definition->key}].");
                }

                $definitions[$definition->key] = $definition;
            }
        }

        $definitions = array_values($definitions);
        usort($definitions, static fn (PortalNavigationItem $a, PortalNavigationItem $b): int => [$a->sort, $a->key] <=> [$b->sort, $b->key]);

        return $definitions;
    }
}