<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Support\ModuleIntegrations\InboundMessaging\Contracts\InboundEmailRouteAutomationWorkspace;
use Throwable;

final class InboundEmailRouteWorkspace
{
    public function __construct(
        private readonly InboundEmailRouteResolver $resolver,
        private readonly RoutedInboundMessageConsumerRegistry $consumers,
        private readonly InboundEmailRouteAutomationWorkspace $automation,
        private readonly InboundEmailContactExtractor $contactExtractor,
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
        $automationByRoute = $this->automation->readForRoutes(
            $routes
                ->map(fn (InboundEmailRoute $route): array => [
                    'key' => (string) $route->key,
                    'label' => (string) $route->label,
                    'is_active' => (bool) $route->is_active,
                ])
                ->values()
                ->all(),
        );

        return [
            'domain' => $domain,
            'domain_ready' => $domain !== null,
            'active_count' => $routes->where('is_active', true)->count(),
            'automation_available' => $this->automation->available(),
            'routes' => $routes
                ->map(fn (InboundEmailRoute $route): array => [
                    'route' => $route,
                    'address' => $domain !== null
                        ? $route->local_part.'@'.$domain
                        : $route->local_part.'@{INBOUND_EMAIL_DOMAIN}',
                    'handling' => $this->handling($route),
                    'contact_extraction' => $this->contactExtraction($route),
                    'automation' => $automationByRoute[(string) $route->key] ?? [
                        'available' => false,
                        'create_url' => null,
                        'automations' => [],
                    ],
                ])
                ->values()
                ->all(),
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function contactExtraction(InboundEmailRoute $route): array
    {
        $definition = is_array($route->contact_extraction_definition)
            ? $route->contact_extraction_definition
            : $this->contactExtractor->defaultDefinition();
        $definition = $this->contactExtractor->normalizeDefinition($definition);
        $required = $definition['required_fields'] ?? [];
        $fields = $definition['fields'] ?? [];
        $labels = $this->contactExtractor->targetLabels();

        return [
            'enabled' => (bool) $route->contact_extraction_enabled,
            'definition' => $definition,
            'status_label' => $route->contact_extraction_enabled
                ? 'Creates or updates a person'
                : 'Off',
            'description' => $route->contact_extraction_enabled
                ? 'Engage extracts the configured fields before contact-aware automation continues.'
                : 'Messages stay Inbox-only unless another connected business process handles them.',
            'targets' => collect($this->contactExtractor->targetKeys())
                ->map(function (string $target) use (
                    $fields,
                    $required,
                    $labels,
                ): array {
                    $field = is_array($fields[$target] ?? null)
                        ? $fields[$target]
                        : [];

                    return [
                        'key' => $target,
                        'label' => $labels[$target] ?? $target,
                        'source' => is_string($field['source'] ?? null)
                            ? $field['source']
                            : InboundEmailContactExtractor::SOURCE_NONE,
                        'marker_label' => is_string($field['label'] ?? null)
                            ? $field['label']
                            : '',
                        'required' => $target === 'email'
                            || in_array($target, $required, true),
                        'source_options' =>
                            $this->contactExtractor->sourceOptions($target),
                    ];
                })
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