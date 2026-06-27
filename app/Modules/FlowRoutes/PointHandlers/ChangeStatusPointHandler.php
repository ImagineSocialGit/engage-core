<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\ChangeStatusPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use Throwable;

class ChangeStatusPointHandler implements PointHandler
{
    public function __construct(
        private readonly TransitionContactWorkflowStatusAction $transitionContactWorkflowStatus,
    ) {}

    public function type(): string
    {
        return Point::TYPE_CHANGE_STATUS;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = ChangeStatusPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_change_status_point_definition',
                meta: [
                    'change_status_definition' => $definition->toMetaPayload(),
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        $contact = Contact::query()->find($context->progress->contact_id);

        if (! $contact) {
            return PointExecutionResult::failed(
                reason: 'change_status_contact_not_found',
                meta: [
                    'contact_id' => $context->progress->contact_id,
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        $status = $this->status($definition);

        if (! $status) {
            return PointExecutionResult::failed(
                reason: 'change_status_target_status_not_found',
                meta: [
                    'change_status_definition' => $definition->toMetaPayload(),
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        $currentStatusId = $context->progress->contact_status_id !== null
            ? (int) $context->progress->contact_status_id
            : null;

        $targetStatusId = (int) $status->getKey();

        if (! $definition->force && $currentStatusId === $targetStatusId) {
            return $this->sameStatusResult($definition, $context, $status);
        }

        try {
            $profile = $this->transitionContactWorkflowStatus->handle(
                contact: $contact,
                toStatus: $status,
                reason: $definition->reason,
                source: 'flow_routes',
                actor: null,
                meta: [
                    ...$definition->meta,
                    'flow_route' => [
                        'flow_route_progress_id' => $context->progress->getKey(),
                        'flow_route_id' => $context->progress->flow_route_id,
                        'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                        'point_id' => $context->flowRoutePoint->point_id,
                    ],
                ],
                force: $definition->force,
            );
        } catch (Throwable $exception) {
            return PointExecutionResult::failed(
                reason: 'change_status_transition_failed',
                meta: [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'change_status_definition' => $definition->toMetaPayload(),
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        return PointExecutionResult::blocked(
            reason: 'workflow_status_changed',
            meta: [
                'contact_workflow_profile_id' => $profile->getKey(),
                'from_contact_status_id' => $currentStatusId,
                'to_contact_status_id' => $targetStatusId,
                'change_status_definition' => $definition->toMetaPayload(),
                'flow_route_progress_id' => $context->progress->getKey(),
                'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                'route_handoff' => [
                    'handled_by' => 'ContactWorkflowStatusChanged',
                    'should_advance_current_progress' => false,
                ],
            ],
        );
    }

    private function status(ChangeStatusPointDefinition $definition): ?ContactStatus
    {
        $query = ContactStatus::query()->active();

        if ($definition->contactStatusId !== null) {
            return $query->whereKey($definition->contactStatusId)->first();
        }

        if ($definition->contactStatusKey !== null) {
            return $query->where('key', $definition->contactStatusKey)->first();
        }

        return null;
    }

    private function sameStatusResult(
        ChangeStatusPointDefinition $definition,
        PointExecutionContext $context,
        ContactStatus $status,
    ): PointExecutionResult {
        $meta = [
            'contact_status_id' => $status->getKey(),
            'contact_status_key' => $status->key,
            'change_status_definition' => $definition->toMetaPayload(),
            'flow_route_progress_id' => $context->progress->getKey(),
            'flow_route_point_id' => $context->flowRoutePoint->getKey(),
        ];

        return match ($definition->onSameStatus) {
            'completed' => PointExecutionResult::completed(
                reason: 'change_status_already_on_target_status',
                meta: $meta,
            ),

            'blocked' => PointExecutionResult::blocked(
                reason: 'change_status_already_on_target_status',
                meta: $meta,
            ),

            'failed' => PointExecutionResult::failed(
                reason: 'change_status_already_on_target_status',
                meta: $meta,
            ),

            default => PointExecutionResult::skipped(
                reason: 'change_status_already_on_target_status',
                meta: $meta,
            ),
        };
    }
}