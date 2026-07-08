<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\CancelCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use Throwable;

class CancelCampaignPointHandler implements PointHandler
{
    public function __construct(
        private readonly CancelCampaignEnrollmentAction $cancelCampaignEnrollment,
    ) {}

    public function type(): string
    {
        return Point::TYPE_CANCEL_CAMPAIGN;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = CancelCampaignPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_cancel_campaign_point_definition',
                meta: [
                    'cancel_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        $contact = Contact::query()->find($context->progress->contact_id);

        if (! $contact) {
            return PointExecutionResult::failed(
                reason: 'cancel_campaign_contact_not_found',
                meta: [
                    'contact_id' => $context->progress->contact_id,
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        try {
            $enrollment = $this->cancelCampaignEnrollment->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->progress,
                reason: $this->renderText($definition->reason, $context),
                skipPendingMessages: $definition->skipPendingMessages,
                meta: $this->meta($definition, $context),
            );
        } catch (Throwable $exception) {
            return PointExecutionResult::failed(
                reason: 'cancel_campaign_failed',
                meta: [
                    'error' => $exception->getMessage(),
                    'cancel_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        if (! $enrollment instanceof CampaignEnrollment) {
            return $this->notEnrolledResult($definition, $context);
        }

        return PointExecutionResult::completed(
            reason: 'campaign_cancelled',
            meta: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'cancel_campaign_definition' => $definition->toMetaPayload(),
                ...$this->resultRouteMeta($context),
            ],
        );
    }

    private function notEnrolledResult(
        CancelCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): PointExecutionResult {
        $meta = [
            'cancel_campaign_definition' => $definition->toMetaPayload(),
            ...$this->resultRouteMeta($context),
        ];

        return match ($definition->onNotEnrolled) {
            CancelCampaignPointDefinition::ON_NOT_ENROLLED_COMPLETED => PointExecutionResult::completed(
                reason: 'campaign_not_enrolled',
                meta: $meta,
            ),

            CancelCampaignPointDefinition::ON_NOT_ENROLLED_BLOCKED => PointExecutionResult::blocked(
                reason: 'campaign_not_enrolled',
                meta: $meta,
            ),

            CancelCampaignPointDefinition::ON_NOT_ENROLLED_FAILED => PointExecutionResult::failed(
                reason: 'campaign_not_enrolled',
                meta: $meta,
            ),

            default => PointExecutionResult::skipped(
                reason: 'campaign_not_enrolled',
                meta: $meta,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(
        CancelCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): array {
        return array_replace_recursive(
            [
                'source' => 'flow_routes',
                'flow_route' => $this->flowRouteProvenance($context),
            ],
            $this->renderArray($definition->meta, $context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resultRouteMeta(PointExecutionContext $context): array
    {
        return [
            'flow_route_progress_id' => $context->progress->getKey(),
            'flow_route_plan_id' => $context->plan?->getKey(),
            'flow_route_plan_item_id' => $context->planItem?->getKey(),
            'flow_route_progress_item_id' => $context->progressItem?->getKey(),
            'flow_route_id' => $context->progress->flow_route_id,
            'flow_route_point_id' => $context->flowRoutePoint->getKey(),
            'flow_route_capability_id' => $context->flowRoutePoint->flow_route_capability_id,
            'point_id' => $context->flowRoutePoint->point_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flowRouteProvenance(PointExecutionContext $context): array
    {
        return [
            'flow_route_progress_id' => $context->progress->getKey(),
            'flow_route_plan_id' => $context->plan?->getKey(),
            'flow_route_plan_item_id' => $context->planItem?->getKey(),
            'flow_route_progress_item_id' => $context->progressItem?->getKey(),
            'flow_route_id' => $context->progress->flow_route_id,
            'flow_route_point_id' => $context->flowRoutePoint->getKey(),
            'flow_route_capability_id' => $context->flowRoutePoint->flow_route_capability_id,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function renderArray(array $values, PointExecutionContext $context): array
    {
        $rendered = [];

        foreach ($values as $key => $value) {
            $rendered[$key] = match (true) {
                is_string($value) => $this->renderText($value, $context),
                is_array($value) => $this->renderArray($value, $context),
                default => $value,
            };
        }

        return $rendered;
    }

    private function renderText(string $value, PointExecutionContext $context): string
    {
        return strtr($value, $this->renderTokens($context));
    }

    /**
     * @return array<string, string>
     */
    private function renderTokens(PointExecutionContext $context): array
    {
        return [
            '{contact.id}' => (string) $context->progress->contact_id,
            '{contact_status.id}' => (string) $context->progress->contact_status_id,
            '{workflow_profile.id}' => (string) $context->progress->contact_workflow_profile_id,
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_progress.id}' => (string) $context->progress->getKey(),
            '{flow_route_plan.id}' => (string) $context->plan?->getKey(),
            '{flow_route_plan_item.id}' => (string) $context->planItem?->getKey(),
            '{flow_route_progress_item.id}' => (string) $context->progressItem?->getKey(),
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{point.id}' => (string) $context->flowRoutePoint->point_id,
            '{subject.type}' => (string) $context->progress->subject_type,
            '{subject.id}' => (string) $context->progress->subject_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentMeta(CampaignEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'channel' => $enrollment->channel,
            'purpose' => $enrollment->purpose,
            'scope' => $enrollment->scope,
            'status' => $enrollment->status,
            'current_step' => $enrollment->current_step,
            'current_campaign_step_id' => $enrollment->current_campaign_step_id,
            'last_scheduled_message_id' => $enrollment->last_scheduled_message_id,
            'flow_route_progress_id' => $enrollment->flow_route_progress_id,
            'flow_route_plan_id' => $enrollment->flow_route_plan_id,
            'flow_route_plan_item_id' => $enrollment->flow_route_plan_item_id,
            'flow_route_progress_item_id' => $enrollment->flow_route_progress_item_id,
            'flow_route_id' => $enrollment->flow_route_id,
            'flow_route_point_id' => $enrollment->flow_route_point_id,
            'flow_route_capability_id' => $enrollment->flow_route_capability_id,
            'started_at' => $enrollment->started_at?->toISOString(),
            'cancelled_at' => $enrollment->cancelled_at?->toISOString(),
            'exited_at' => $enrollment->exited_at?->toISOString(),
            'exit_reason' => $enrollment->exit_reason,
        ];
    }
}
