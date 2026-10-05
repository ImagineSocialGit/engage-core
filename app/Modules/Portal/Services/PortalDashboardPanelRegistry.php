<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Contracts\PortalDashboardPanelProvider;
use App\Modules\Portal\Data\PortalDashboardPanel;
use App\Modules\Portal\Models\PortalUser;
use LogicException;

final class PortalDashboardPanelRegistry
{
    public const TAG = 'portal.dashboard_panel_providers';

    public function __construct(private readonly iterable $providers) {}

    /** @return array<int, PortalDashboardPanel> */
    public function forUser(PortalUser $user): array
    {
        $definitions = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof PortalDashboardPanelProvider) {
                continue;
            }

            foreach ($provider->definitions($user) as $definition) {
                if (! $definition instanceof PortalDashboardPanel) {
                    throw new LogicException('Portal dashboard panel providers must yield PortalDashboardPanel instances.');
                }

                if ($definition->requiresVerifiedEmail && ! $user->hasVerifiedEmail()) {
                    continue;
                }

                if (array_key_exists($definition->key, $definitions)) {
                    throw new LogicException("Duplicate Portal dashboard panel key [{$definition->key}].");
                }

                $definitions[$definition->key] = $definition;
            }
        }

        $definitions = array_values($definitions);
        usort($definitions, static fn (PortalDashboardPanel $a, PortalDashboardPanel $b): int => [$a->sort, $a->key] <=> [$b->sort, $b->key]);

        return $definitions;
    }
}