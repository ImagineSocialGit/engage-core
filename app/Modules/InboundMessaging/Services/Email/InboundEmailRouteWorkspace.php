<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use Throwable;

final class InboundEmailRouteWorkspace
{
    public function __construct(
        private readonly InboundEmailRouteResolver $resolver,
        private readonly RoutedInboundMessageConsumerRegistry $consumers,
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
                    'handling' => $this->handling($route),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{status: string, label: string, description: string}
     */
    private function handling(InboundEmailRoute $route): array
    {
        try {
            $matches = $this->consumers->matching(
                InboundEmailRouteIdentity::fromRoute($route),
            );
        } catch (Throwable) {
            return [
                'status' => 'problem',
                'label' => 'Needs setup attention',
                'description' => 'This address could not determine which business process should receive its messages.',
            ];
        }

        if ($matches === []) {
            return [
                'status' => 'inbox',
                'label' => 'Inbox only',
                'description' => 'Messages received here stay available for manual review in the Inbox.',
            ];
        }

        if (count($matches) > 1) {
            return [
                'status' => 'problem',
                'label' => 'Needs setup attention',
                'description' => 'More than one business process is connected to this address.',
            ];
        }

        return [
            'status' => 'connected',
            'label' => $this->consumers->consumerLabel($matches[0]),
            'description' => 'Messages still appear in the Inbox and are also passed to this business process.',
        ];
    }
}