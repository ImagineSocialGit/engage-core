<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\EnrollCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use InvalidArgumentException;
use Throwable;

class EnrollCampaignPointHandler implements PointHandler
{
    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContactInCampaign,
    ) {}

    public function type(): string
    {
        return Point::TYPE_ENROLL_CAMPAIGN;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = EnrollCampaignPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_enroll_campaign_point_definition',
                meta: [
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        $contact = Contact::query()->find($context->progress->contact_id);

        if (! $contact) {
            return PointExecutionResult::failed(
                reason: 'enroll_campaign_contact_not_found',
                meta: [
                    'contact_id' => $context->progress->contact_id,
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        $campaign = $this->activeCampaign($definition);

        if (! $campaign instanceof Campaign) {
            return PointExecutionResult::skipped(
                reason: 'campaign_not_active_or_missing',
                meta: [
                    'campaign_key' => $definition->campaignKey,
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        $existingEnrollment = $this->existingEnrollment($contact, $definition);

        if ($existingEnrollment instanceof CampaignEnrollment) {
            return $this->alreadyEnrolledResult(
                enrollment: $existingEnrollment,
                definition: $definition,
                context: $context,
            );
        }

        try {
            $enrollment = $this->enrollContactInCampaign->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->progress,
                payload: $this->payload($definition, $context),
                meta: $this->meta($definition, $context),
                startContext: $this->startContext($definition, $context),
                exitConditions: $this->exitConditions($definition, $context),
            );
        } catch (InvalidArgumentException $exception) {
            return PointExecutionResult::skipped(
                reason: 'campaign_enrollment_not_schedulable',
                meta: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        } catch (Throwable $exception) {
            return PointExecutionResult::failed(
                reason: 'enroll_campaign_failed',
                meta: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                    ...$this->resultRouteMeta($context),
                ],
            );
        }

        return PointExecutionResult::completed(
            reason: 'campaign_enrolled',
            meta: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'enroll_campaign_definition' => $definition->toMetaPayload(),
                ...$this->resultRouteMeta($context),
            ],
        );
    }

    private function activeCampaign(EnrollCampaignPointDefinition $definition): ?Campaign
    {
        return Campaign::query()
            ->active()
            ->where('key', $definition->campaignKey)
            ->first();
    }

    private function existingEnrollment(
        Contact $contact,
        EnrollCampaignPointDefinition $definition,
    ): ?CampaignEnrollment {
        return CampaignEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where('campaign_key', $definition->campaignKey)
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->first();
    }

    private function alreadyEnrolledResult(
        CampaignEnrollment $enrollment,
        EnrollCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): PointExecutionResult {
        $meta = [
            'campaign_enrollment' => $this->enrollmentMeta($enrollment),
            'enroll_campaign_definition' => $definition->toMetaPayload(),
            ...$this->resultRouteMeta($context),
        ];

        return match ($definition->onAlreadyEnrolled) {
            EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_COMPLETED => PointExecutionResult::completed(
                reason: 'campaign_already_enrolled',
                meta: $meta,
            ),

            EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_BLOCKED => PointExecutionResult::blocked(
                reason: 'campaign_already_enrolled',
                meta: $meta,
            ),

            EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_FAILED => PointExecutionResult::failed(
                reason: 'campaign_already_enrolled',
                meta: $meta,
            ),

            default => PointExecutionResult::skipped(
                reason: 'campaign_already_enrolled',
                meta: $meta,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        EnrollCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): array {
        return array_replace_recursive(
            $this->renderArray($definition->payload, $context),
            [
                'runtime_context' => $this->runtimeContext($context),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(
        EnrollCampaignPointDefinition $definition,
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
     * @return array<string, mixed>|null
     */
    private function startContext(
        EnrollCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): ?array {
        if ($definition->startContext === null) {
            return null;
        }

        return array_replace_recursive(
            $this->runtimeContext($context),
            $this->renderArray($definition->startContext, $context),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exitConditions(
        EnrollCampaignPointDefinition $definition,
        PointExecutionContext $context,
    ): ?array {
        if ($definition->exitConditions === null) {
            return null;
        }

        return $this->renderArray($definition->exitConditions, $context);
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
     * @return array<string, mixed>
     */
    private function runtimeContext(PointExecutionContext $context): array
    {
        return array_replace($this->flowRouteProvenance($context), [
            'point_id' => $context->flowRoutePoint->point_id,
            'contact_id' => $context->progress->contact_id,
            'contact_status_id' => $context->progress->contact_status_id,
            'workflow_profile_id' => $context->progress->contact_workflow_profile_id,
            'subject_type' => $context->progress->subject_type,
            'subject_id' => $context->progress->subject_id,
        ]);
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
            'exited_at' => $enrollment->exited_at?->toISOString(),
            'exit_reason' => $enrollment->exit_reason,
        ];
    }
}
