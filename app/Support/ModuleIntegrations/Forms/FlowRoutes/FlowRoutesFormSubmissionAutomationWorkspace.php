<?php

namespace App\Support\ModuleIntegrations\Forms\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRouteAuthoringLinkBuilder;
use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;
use App\Support\Modules\ModuleManager;

final class FlowRoutesFormSubmissionAutomationWorkspace implements FormSubmissionAutomationWorkspace
{
    private const GUIDED_SURFACE = 'forms.after_submission';

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
        private readonly ModuleManager $modules,
        private readonly FlowRouteAuthoringLinkBuilder $authoringLinks,
        private readonly QuickFormSubmissionAutomationService $quickActions,
    ) {}

    public function readForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
        ?HostedFormContextReference $context = null,
        ?string $contextLabel = null,
    ): array {
        $formKey = trim($formKey);
        $formName = trim($formName);
        $contextLabel = $this->nullableString($contextLabel);

        return [
            'available' => true,
            'contact_available' => $contactAvailable,
            'actions' => $contactAvailable
                ? $this->actions(
                    formKey: $formKey,
                    formName: $formName,
                    context: $context,
                    contextLabel: $contextLabel,
                )
                : [],
            'automations' => $this->automations(
                formKey: $formKey,
                formName: $formName,
                context: $context,
                contextLabel: $contextLabel,
            ),
        ];
    }

    public function createQuickActionForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
        string $capabilityKey,
        array $input,
        ?HostedFormContextReference $context = null,
        ?string $contextLabel = null,
    ): int {
        return $this->quickActions->create(
            formKey: $formKey,
            formName: $formName,
            contactAvailable: $contactAvailable,
            capabilityKey: $capabilityKey,
            input: $input,
            context: $context,
            contextLabel: $contextLabel,
        );
    }

    private function actions(
        string $formKey,
        string $formName,
        ?HostedFormContextReference $context,
        ?string $contextLabel,
    ): array {
        $enabledModules = $this->modules->enabledKeysWithDependencies();
        $actions = [];

        foreach ($this->capabilities->definitions() as $capability) {
            if (! $this->guidedCapabilityAvailable(
                capability: $capability,
                enabledModules: $enabledModules,
            )) {
                continue;
            }

            $presentation = $this->guidedPresentation($capability);

            if ($presentation === null) {
                continue;
            }

            $nameTarget = $contextLabel ?? $formName;
            $inlineFields = $this->quickActions->inlineFields($capability);

            $actions[] = [
                'key' => $capability->key,
                'module_key' => $capability->moduleKey,
                'inline_fields' => $inlineFields,
                'label' => $this->presentationString(
                    $presentation,
                    'label',
                    $capability->name,
                ),
                'detail' => $this->presentationString(
                    $presentation,
                    'detail',
                    $capability->description ?? '',
                ),
                'url' => $this->authoringLinks->createUrl(
                    triggerAuthoringKey: FormSubmissionAutomationTriggerAuthoringContributor::KEY,
                    triggerValues: $this->triggerValues($formKey, $context),
                    name: $this->presentationString(
                        $presentation,
                        'name_prefix',
                        $capability->name,
                    ).' '.$nameTarget,
                    kind: FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                    starterCapabilityKey: $capability->key,
                ),
                '_sort_order' => is_numeric($presentation['sort_order'] ?? null)
                    ? (int) $presentation['sort_order']
                    : 500,
            ];
        }

        usort(
            $actions,
            static fn (array $left, array $right): int =>
                ($left['_sort_order'] <=> $right['_sort_order'])
                ?: strnatcasecmp($left['label'], $right['label']),
        );

        $actions = array_map(
            static function (array $action): array {
                unset($action['_sort_order']);

                return $action;
            },
            $actions,
        );

        $nameTarget = $contextLabel ?? $formName;

        $actions[] = [
            'key' => 'flow_routes.custom',
            'module_key' => 'flow_routes',
            'label' => 'Build custom automation',
            'detail' => 'Use a Route when the form should start several actions, waits, or decisions.',
            'url' => $this->authoringLinks->createUrl(
                triggerAuthoringKey: FormSubmissionAutomationTriggerAuthoringContributor::KEY,
                triggerValues: $this->triggerValues($formKey, $context),
                name: 'After '.$nameTarget.' signup',
                kind: FlowRoute::AUTHORING_KIND_ROUTE,
            ),
        ];

        return array_values($actions);
    }

    private function guidedCapabilityAvailable(
        AutomationCapabilityDefinition $capability,
        array $enabledModules,
    ): bool {
        if (! $capability->isActive
            || $capability->capabilityType !== AutomationCapabilityDefinition::TYPE_ACTION
            || $capability->actionKey === null
            || $this->guidedPresentation($capability) === null
        ) {
            return false;
        }

        $requiredModules = array_values(array_unique([
            $capability->moduleKey,
            ...$capability->requiredModules,
        ]));

        return array_diff($requiredModules, $enabledModules) === [];
    }

    private function guidedPresentation(
        AutomationCapabilityDefinition $capability,
    ): ?array {
        $surfaces = $capability->meta['guided_surfaces'] ?? null;

        if (! is_array($surfaces)) {
            return null;
        }

        $presentation = $surfaces[self::GUIDED_SURFACE] ?? null;

        return is_array($presentation) ? $presentation : null;
    }

    private function presentationString(
        array $presentation,
        string $key,
        string $fallback,
    ): string {
        $value = $this->nullableString($presentation[$key] ?? null);

        return $value ?? $fallback;
    }

    private function triggerValues(
        string $formKey,
        ?HostedFormContextReference $context,
    ): array {
        return array_filter([
            'form_key' => $formKey,
            'context_key' => $context?->key,
            'context_reference' => $context?->reference,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    private function automations(
        string $formKey,
        string $formName,
        ?HostedFormContextReference $context,
        ?string $contextLabel,
    ): array {
        return FlowRoute::query()
            ->currentVersion()
            ->forAutomationEvent(FormSubmissionAutomationTriggerAuthoringContributor::EVENT_KEY)
            ->withCount('activeFlowRoutePoints')
            ->orderBy('name')
            ->get()
            ->reject(fn (FlowRoute $route): bool => $route->isArchivedFromAuthoring())
            ->filter(fn (FlowRoute $route): bool => $this->matchesForm(
                route: $route,
                formKey: $formKey,
            ))
            ->filter(fn (FlowRoute $route): bool => $this->matchesContext(
                route: $route,
                context: $context,
            ))
            ->map(function (FlowRoute $route) use (
                $formName,
                $contextLabel,
            ): array {
                $scope = $this->contextScope($route);
                $contextScoped = $scope['scoped'];

                return [
                    'id' => (int) $route->getKey(),
                    'name' => (string) $route->name,
                    'kind' => $route->authoringKind(),
                    'is_enabled' => (bool) $route->is_active,
                    'step_count' => (int) $route->active_flow_route_points_count,
                    'url' => $this->authoringLinks->editUrl($route),
                    'scope' => $contextScoped ? 'context' : 'form',
                    'scope_label' => $contextScoped
                        ? ($contextLabel ?? 'This signup context')
                        : 'All '.$formName.' submissions',
                ];
            })
            ->values()
            ->all();
    }

    private function matchesForm(
        FlowRoute $route,
        string $formKey,
    ): bool {
        foreach ($this->entryConditions($route) as $condition) {
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

    private function matchesContext(
        FlowRoute $route,
        ?HostedFormContextReference $context,
    ): bool {
        if ($context === null) {
            return true;
        }

        $scope = $this->contextScope($route);

        if (! $scope['scoped']) {
            return true;
        }

        return $scope['key'] === $context->key
            && $scope['reference'] === $context->reference;
    }

    private function contextScope(FlowRoute $route): array
    {
        $key = null;
        $reference = null;

        foreach ($this->entryConditions($route) as $condition) {
            if (($condition['source'] ?? null) !== 'execution_meta'
                || ($condition['operator'] ?? null) !== 'equals'
            ) {
                continue;
            }

            $path = $condition['path'] ?? null;
            $value = $this->nullableString($condition['value'] ?? null);

            if ($path === FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_KEY_EVENT_PATH) {
                $key = $value;
            }

            if ($path === FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_REFERENCE_EVENT_PATH) {
                $reference = $value;
            }
        }

        return [
            'scoped' => $key !== null || $reference !== null,
            'key' => $key,
            'reference' => $reference,
        ];
    }

    private function entryConditions(FlowRoute $route): array
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions)) {
            return [];
        }

        return array_values(array_filter(
            $conditions,
            static fn (mixed $condition): bool => is_array($condition),
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}