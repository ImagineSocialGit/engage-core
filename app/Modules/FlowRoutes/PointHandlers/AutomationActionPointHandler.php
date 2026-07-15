<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class AutomationActionPointHandler implements PointHandler
{
    public const DYNAMIC_TYPE = '__automation_action__';

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
        private readonly AutomationActionRegistry $actions,
    ) {}

    public function type(): string
    {
        return self::DYNAMIC_TYPE;
    }

    public function supports(string $pointType): bool
    {
        $definition = $this->capabilityDefinitionForPointType($pointType);

        return $definition?->actionKey !== null
            && $this->actions->has($definition->actionKey);
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = $this->capabilityDefinition($context);

        if (! $definition instanceof AutomationCapabilityDefinition
            || $definition->actionKey === null
        ) {
            return PointExecutionResult::failed(
                reason: 'automation_action_capability_unavailable',
                meta: [
                    'point_type' => $context->pointType(),
                    'flow_routes' => $context->flowRouteProvenance(),
                ],
            );
        }

        $action = $this->actions->get($definition->actionKey);

        if ($action === null) {
            return PointExecutionResult::failed(
                reason: 'automation_action_handler_unavailable',
                meta: [
                    'action_key' => $definition->actionKey,
                    'point_type' => $context->pointType(),
                    'flow_routes' => $context->flowRouteProvenance(),
                ],
            );
        }

        [$contact, $subject] = $this->models($context);
        $provenance = $context->flowRouteProvenance();

        try {
            $result = $action->handle(new AutomationActionContext(
                input: $this->renderArray(
                    array_replace_recursive($context->definition, $context->settings),
                    $context,
                ),
                subject: $subject,
                models: array_filter([
                    'current_contact' => $contact,
                    'current_subject' => $subject,
                ], static fn (mixed $model): bool => $model instanceof Model),
                source: $context->progress,
                behaviorOwner: $context->flowRoutePoint,
                executionKey: $this->executionKey($context),
                surface: 'flow_routes',
                runtimeContext: $provenance + [
                    'contact_id' => $context->progress->contact_id,
                    'contact_status_id' => $context->progress->contact_status_id,
                    'workflow_profile_id' => $context->progress->contact_workflow_profile_id,
                    'subject_type' => $context->progress->subject_type,
                    'subject_id' => $context->progress->subject_id,
                ],
                meta: [
                    'source' => 'flow_routes',
                    'flow_route' => $provenance,
                ],
                occurredAt: now(),
            ));
        } catch (Throwable $exception) {
            return PointExecutionResult::failed(
                reason: 'automation_action_execution_failed',
                meta: [
                    'action_key' => $definition->actionKey,
                    'point_type' => $context->pointType(),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'flow_routes' => $provenance,
                ],
            );
        }

        $this->recordCorrelation($context, $result);

        return $this->pointResult($result, $provenance);
    }

    private function capabilityDefinition(
        PointExecutionContext $context,
    ): ?AutomationCapabilityDefinition {
        $context->flowRoutePoint->loadMissing('capability');
        $capability = $context->flowRoutePoint->capability;

        if ($capability instanceof FlowRouteCapability
            && is_string($capability->action_key)
            && trim($capability->action_key) !== ''
        ) {
            foreach ($this->capabilities->definitions() as $definition) {
                if ($definition->key === $capability->key) {
                    return $definition;
                }
            }
        }

        return $this->capabilityDefinitionForPointType($context->pointType());
    }

    private function capabilityDefinitionForPointType(
        string $pointType,
    ): ?AutomationCapabilityDefinition {
        $matches = array_values(array_filter(
            $this->capabilities->definitions(),
            static fn (AutomationCapabilityDefinition $definition): bool => $definition->pointType === $pointType
                && $definition->actionKey !== null,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return array{0: Model|null, 1: Model|null}
     */
    private function models(PointExecutionContext $context): array
    {
        $progress = $context->progress->loadMissing(['contact', 'subject']);
        $contact = $progress->contact;
        $subject = $progress->subject;

        $contact = $contact instanceof Model ? $contact : null;
        $subject = $subject instanceof Model ? $subject : $contact;

        return [$contact, $subject];
    }

    private function recordCorrelation(
        PointExecutionContext $context,
        AutomationActionResult $result,
    ): void {
        $artifact = $result->primaryArtifact();

        if (! $context->progressItem
            || ! $artifact instanceof Model
            || $result->correlationKey === null
            || $result->correlationType === null
        ) {
            return;
        }

        $context->progressItem->forceFill([
            'created_subject_type' => $artifact->getMorphClass(),
            'created_subject_id' => $artifact->getKey(),
            'correlation_key' => $result->correlationKey,
            'correlation_type' => $result->correlationType,
            'correlation' => $result->correlation,
        ])->save();
    }

    /**
     * @param array<string, mixed> $provenance
     */
    private function pointResult(
        AutomationActionResult $result,
        array $provenance,
    ): PointExecutionResult {
        $meta = array_replace_recursive(
            $result->output,
            $result->meta,
            ['flow_routes' => $provenance],
        );

        return match ($result->status) {
            AutomationActionResult::STATUS_COMPLETED => PointExecutionResult::completed(
                $result->reason,
                $meta,
            ),
            AutomationActionResult::STATUS_SKIPPED => PointExecutionResult::skipped(
                $result->reason,
                $meta,
            ),
            AutomationActionResult::STATUS_BLOCKED => PointExecutionResult::blocked(
                $result->reason,
                $meta,
            ),
            default => PointExecutionResult::failed(
                $result->reason,
                $meta,
            ),
        };
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function renderArray(
        array $values,
        PointExecutionContext $context,
    ): array {
        $rendered = [];

        foreach ($values as $key => $value) {
            $rendered[$key] = match (true) {
                is_string($value) => strtr($value, $this->renderTokens($context)),
                is_array($value) => $this->renderArray($value, $context),
                default => $value,
            };
        }

        return $rendered;
    }

    /** @return array<string, string> */
    private function renderTokens(PointExecutionContext $context): array
    {
        return [
            '{contact.id}' => (string) $context->progress->contact_id,
            '{contact_status.id}' => (string) $context->progress->contact_status_id,
            '{workflow_profile.id}' => (string) $context->progress->contact_workflow_profile_id,
            '{flow_route_progress.id}' => (string) $context->progress->getKey(),
            '{flow_route_plan.id}' => (string) $context->plan?->getKey(),
            '{flow_route_plan_item.id}' => (string) $context->planItem?->getKey(),
            '{flow_route_progress_item.id}' => (string) $context->progressItem?->getKey(),
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{subject.type}' => (string) $context->progress->subject_type,
            '{subject.id}' => (string) $context->progress->subject_id,
        ];
    }

    private function executionKey(PointExecutionContext $context): string
    {
        return implode(':', array_filter([
            'flow_route_point',
            $context->progressItem?->getKey(),
            $context->progress->getKey(),
            $context->flowRoutePoint->getKey(),
        ], static fn (mixed $value): bool => $value !== null));
    }
}
