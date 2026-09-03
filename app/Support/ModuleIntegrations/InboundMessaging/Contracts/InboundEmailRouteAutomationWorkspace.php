<?php

namespace App\Support\ModuleIntegrations\InboundMessaging\Contracts;

interface InboundEmailRouteAutomationWorkspace
{
    public function available(): bool;

    /**
     * @param array<int, array{key: string, label: string, is_active: bool}> $routes
     * @return array<string, array{
     *     available: bool,
     *     create_url: string|null,
     *     automations: array<int, array{
     *         id: int,
     *         name: string,
     *         kind: string,
     *         is_enabled: bool,
     *         step_count: int,
     *         step_label: string,
     *         scope: string,
     *         url: string
     *     }>
     * }>
     */
    public function readForRoutes(array $routes): array;
}