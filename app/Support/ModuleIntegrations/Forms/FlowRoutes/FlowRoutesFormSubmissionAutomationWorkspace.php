<?php

namespace App\Support\ModuleIntegrations\Forms\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;
use App\Support\Modules\ModuleManager;

final class FlowRoutesFormSubmissionAutomationWorkspace implements FormSubmissionAutomationWorkspace
{
    private const GUIDED_ACTIONS = [
        'tasks.create_task' => [
            'module_key' => 'tasks',
            'label' => 'Create follow-up task',
            'detail' => 'Create a template-backed Task for the Contact after this form is submitted.',
            'name_prefix' => 'Follow up after',
        ],
        'messaging.send_message' => [
            'module_key' => 'messaging',
            'label' => 'Send automatic message',
            'detail' => 'Send a reusable message automatically after this form is submitted.',
            'name_prefix' => 'Reply after',
        ],
    ];

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
        private readonly ModuleManager $modules,
    ) {}

    public function readForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
    ): array {
        $formKey = trim($formKey);
        $formName = trim($formName);

        return [
            'available' => true,
            'contact_available' => $contactAvailable,
            'actions' => $contactAvailable
                ? $this->actions($formKey, $formName)
                : [],
            'automations' => $this->automations($formKey),
        ];
    }

    /** @return array<int, array{key: string, module_key: string, label: string, detail: string, url: string}> */
    private function actions(string $formKey, string $formName): array
    {
        $definitions = $this->capabilities->definitions();
        $enabledModules = $this->modules->enabledKeysWithDependencies();
        $actions = [];

        foreach (self::GUIDED_ACTIONS as $capabilityKey => $definition) {
            $capability = $definitions[$capabilityKey] ?? null;

            if ($capability === null
                || ! $capability->isActive
                || ! in_array($definition['module_key'], $enabledModules, true)
            ) {
                continue;
            }

            $actions[] = [
                'key' => $capabilityKey,
                'module_key' => $definition['module_key'],
                'label' => $definition['label'],
                'detail' => $definition['detail'],
                'url' => $this->createUrl(
                    formKey: $formKey,
                    name: $definition['name_prefix'].' '.$formName,
                    kind: FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                    starterCapabilityKey: $capabilityKey,
                ),
            ];
        }

        $actions[] = [
            'key' => 'flow_routes.custom',
            'module_key' => 'flow_routes',
            'label' => 'Build custom automation',
            'detail' => 'Use a Route when the form should start several actions, waits, or decisions.',
            'url' => $this->createUrl(
                formKey: $formKey,
                name: 'After '.$formName.' submission',
                kind: FlowRoute::AUTHORING_KIND_ROUTE,
            ),
        ];

        return $actions;
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     kind: string,
     *     is_enabled: bool,
     *     step_count: int,
     *     url: string
     * }>
     */
    private function automations(string $formKey): array
    {
        return FlowRoute::query()
            ->currentVersion()
            ->forAutomationEvent(FormSubmissionAutomationTriggerAuthoringContributor::EVENT_KEY)
            ->withCount('activeFlowRoutePoints')
            ->orderBy('name')
            ->get()
            ->reject(fn (FlowRoute $route): bool => $route->isArchivedFromAuthoring())
            ->filter(fn (FlowRoute $route): bool => $this->matchesForm($route, $formKey))
            ->map(fn (FlowRoute $route): array => [
                'id' => (int) $route->getKey(),
                'name' => (string) $route->name,
                'kind' => $route->authoringKind(),
                'is_enabled' => (bool) $route->is_active,
                'step_count' => (int) $route->active_flow_route_points_count,
                'url' => route('crm.flow-routes.index', [
                    'edit_route' => $route->getKey(),
                ]),
            ])
            ->values()
            ->all();
    }

    private function matchesForm(FlowRoute $route, string $formKey): bool
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (($condition['source'] ?? null) === 'execution_meta'
                && ($condition['path'] ?? null) === FormSubmissionAutomationTriggerAuthoringContributor::FORM_KEY_EVENT_PATH
                && ($condition['operator'] ?? null) === 'equals'
                && (string) ($condition['value'] ?? '') === $formKey
            ) {
                return true;
            }
        }

        return false;
    }

    private function createUrl(
        string $formKey,
        string $name,
        string $kind,
        ?string $starterCapabilityKey = null,
    ): string {
        $parameters = [
            'create' => 1,
            'create_kind' => $kind,
            'trigger_authoring_key' => FormSubmissionAutomationTriggerAuthoringContributor::KEY,
            'form_key' => $formKey,
            'create_name' => mb_substr($name, 0, 255),
        ];

        if ($starterCapabilityKey !== null) {
            $parameters['starter_capability_key'] = $starterCapabilityKey;
        }

        return route('crm.flow-routes.index', $parameters);
    }
}