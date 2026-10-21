<?php

namespace App\Support\ModuleIntegrations\Forms\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Services\FlowRouteActivationService;
use App\Modules\FlowRoutes\Services\FlowRoutePointAuthoringService;
use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Forms-facing quick authoring seam. No Form or Event stores a second
 * automation definition; one-step behaviors use the normal FlowRoutes runtime.
 */
final class QuickFormSubmissionAutomationService
{
    private const SURFACE = 'forms.after_submission';

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
        private readonly AutomationPointAuthoringRegistry $pointAuthoring,
        private readonly AutomationTriggerAuthoringRegistry $triggers,
        private readonly FlowRoutePointAuthoringService $points,
        private readonly FlowRouteActivationService $activation,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * null means this action must continue through Advanced Route authoring.
     * A contributor must opt in and expose only simple, supported fields.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function inlineFields(AutomationCapabilityDefinition $definition): ?array
    {
        if (! $this->eligible($definition)) {
            return null;
        }

        $capability = FlowRouteCapability::query()
            ->active()
            ->where('key', $definition->key)
            ->first();

        if (! $capability instanceof FlowRouteCapability
            || $capability->point_type !== $definition->pointType
            || data_get($capability->meta, 'runtime.handler_available_at_sync', true) === false
            || ! $this->pointAuthoring->has($definition->pointType)
        ) {
            return null;
        }

        $context = new AutomationPointAuthoringContext(capability: $capability);

        if (! $this->pointAuthoring->available($definition->pointType, $context)) {
            return null;
        }

        $fields = $this->pointAuthoring->fields($definition->pointType, [], $context);

        if ($fields === [] || count($fields) > 4) {
            return null;
        }

        foreach ($fields as $field) {
            if (! is_array($field)
                || ! in_array($field['type'] ?? null, ['text', 'select', 'textarea'], true)
                || preg_match('/\A[a-z][a-z0-9_]*\z/D', (string) ($field['name'] ?? '')) !== 1
            ) {
                return null;
            }

            if (($field['type'] ?? null) === 'select' && ! is_array($field['options'] ?? null)) {
                return null;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $input */
    public function create(
        string $formKey,
        string $formName,
        bool $contactAvailable,
        string $capabilityKey,
        array $input,
        ?HostedFormContextReference $context = null,
        ?string $contextLabel = null,
    ): int {
        if (! $contactAvailable) {
            throw ValidationException::withMessages([
                'quick_action' => 'This form does not currently map submissions to Contacts.',
            ]);
        }

        $definition = $this->capabilities->definitions()[$capabilityKey] ?? null;
        $fields = $definition instanceof AutomationCapabilityDefinition
            ? $this->inlineFields($definition)
            : null;

        if ($fields === null) {
            throw ValidationException::withMessages([
                'quick_action' => 'This action is unavailable for inline setup. Use Advanced automation.',
            ]);
        }

        $capability = FlowRouteCapability::query()
            ->active()
            ->where('key', $definition->key)
            ->firstOrFail();

        $pointContext = new AutomationPointAuthoringContext(capability: $capability);
        $allowedFields = array_column($fields, 'name');
        $values = array_intersect_key($input, array_flip($allowedFields));
        $values = Validator::make(
            $values,
            $this->pointAuthoring->rules($definition->pointType, $pointContext),
        )->validate();

        $triggerInput = [
            'form_key' => trim($formKey),
            'context_key' => $context?->key,
            'context_reference' => $context?->reference,
        ];
        $triggerKey = FormSubmissionAutomationTriggerAuthoringContributor::KEY;

        if (! $this->triggers->available($triggerKey)) {
            throw ValidationException::withMessages([
                'quick_action' => 'The form submission trigger is unavailable.',
            ]);
        }

        Validator::make($triggerInput, $this->triggers->rules($triggerKey))->validate();
        $selection = $this->triggers->selection($triggerKey, $triggerInput);
        $presentation = $definition->meta['guided_surfaces'][self::SURFACE] ?? [];
        $prefix = trim((string) ($presentation['name_prefix'] ?? $definition->name));
        $target = trim((string) ($contextLabel ?: $formName));
        $previewDefinition = $this->pointAuthoring->buildDefinition(
            $definition->pointType, $values, $pointContext,
        );
        $summary = trim($this->pointAuthoring->editorSummary(
            $definition->pointType, $previewDefinition, $pointContext,
        ));
        $name = mb_substr(trim($prefix.' '.$target.($summary !== '' ? ' — '.$summary : '')), 0, 255);

        return DB::transaction(function () use ($selection, $triggerKey, $name, $capability, $values): int {
            $route = FlowRoute::query()->create([
                'key' => 'crm_route_'.Str::lower((string) Str::uuid()),
                'contact_status_id' => $selection->contactStatusId,
                'owner_type' => null,
                'owner_id' => null,
                'owner_group' => 'client',
                'name' => $name,
                'description' => null,
                'version' => 1,
                'is_current_version' => true,
                'trigger_type' => $selection->triggerType,
                'trigger_key' => $selection->triggerKey,
                'is_active' => false,
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => [
                    'authoring' => [
                        'source' => self::SURFACE,
                        'kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                        'trigger_authoring_key' => $triggerKey,
                    ],
                    'definition' => ['entry_conditions' => $selection->entryConditions],
                ],
            ]);

            $this->points->create($route, (int) $capability->getKey(), $values);
            $this->activation->enable($route);

            return (int) $route->getKey();
        });
    }

    private function eligible(AutomationCapabilityDefinition $definition): bool
    {
        if (! $definition->isActive
            || $definition->capabilityType !== AutomationCapabilityDefinition::TYPE_ACTION
            || $definition->actionKey === null
        ) {
            return false;
        }

        $presentation = $definition->meta['guided_surfaces'][self::SURFACE] ?? null;

        if (! is_array($presentation) || ($presentation['inline_authoring'] ?? false) !== true) {
            return false;
        }

        $enabled = $this->modules->enabledKeysWithDependencies();

        return array_diff(array_unique([
            $definition->moduleKey,
            ...$definition->requiredModules,
        ]), $enabled) === [];
    }
}