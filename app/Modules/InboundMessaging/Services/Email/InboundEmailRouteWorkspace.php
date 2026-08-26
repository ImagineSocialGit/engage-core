<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;

final class InboundEmailRouteWorkspace
{
    public function __construct(
        private readonly InboundEmailRouteResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $domain = $this->resolver->configuredDomain();
        $routes = InboundEmailRoute::query()
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->orderBy('key')
            ->get();

        return [
            'domain' => $domain,
            'domain_ready' => $domain !== null,
            'active_count' => $routes->where('is_active', true)->count(),
            'routes' => $routes
                ->map(fn (InboundEmailRoute $route): array => [
                    'route' => $route,
                    'address' => $domain !== null
                        ? $route->local_part.'@'.$domain
                        : $route->local_part.'@{INBOUND_EMAIL_DOMAIN}',
                ])
                ->values()
                ->all(),
        ];
    }
}