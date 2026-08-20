<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationEventWorkflowContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_event_progress_snapshots_current_workflow_context_and_branches_on_contact_status(): void
    {
        $scenario = $this->scenario(currentStatusKey: 'eligible');

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: 'test.workflow_context',
                contactId: $scenario['contact']->getKey(),
                subjectType: 'test_subject',
                subjectId: 77,
                occurredAt: now(),
            ),
        );

        $progress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $scenario['flow_route']->getKey())
            ->firstOrFail();

        $this->assertSame(
            $scenario['workflow_profile']->getKey(),
            $progress->contact_workflow_profile_id,
        );
        $this->assertSame(
            $scenario['contact_status']->getKey(),
            $progress->contact_status_id,
        );
        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $progress->status);
        $this->assertSame(
            $scenario['eligible_wait']->getKey(),
            $progress->current_flow_route_point_id,
        );
    }

    public function test_automation_event_status_branch_uses_default_path_for_nonmatching_current_status(): void
    {
        $scenario = $this->scenario(currentStatusKey: 'ineligible');

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: 'test.workflow_context',
                contactId: $scenario['contact']->getKey(),
                subjectType: 'test_subject',
                subjectId: 88,
                occurredAt: now(),
            ),
        );

        $progress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $scenario['flow_route']->getKey())
            ->firstOrFail();

        $this->assertSame(
            $scenario['workflow_profile']->getKey(),
            $progress->contact_workflow_profile_id,
        );
        $this->assertSame(
            $scenario['contact_status']->getKey(),
            $progress->contact_status_id,
        );
        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $progress->status);
        $this->assertSame(
            $scenario['ineligible_wait']->getKey(),
            $progress->current_flow_route_point_id,
        );
    }

    /**
     * @return array{
     *     contact: Contact,
     *     contact_status: ContactStatus,
     *     workflow_profile: ContactWorkflowProfile,
     *     flow_route: FlowRoute,
     *     eligible_wait: FlowRoutePoint,
     *     ineligible_wait: FlowRoutePoint,
     * }
     */
    private function scenario(string $currentStatusKey): array
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => $currentStatusKey,
            'name' => ucfirst($currentStatusKey),
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $contact = Contact::factory()->create();

        $workflowProfile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $contactStatus->getKey(),
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'test_workflow_context_route_'.$currentStatusKey,
            'contact_status_id' => null,
            'name' => 'Test Workflow Context Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'test.workflow_context',
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $branch = $this->point(
            flowRoute: $flowRoute,
            key: 'route_by_contact_status',
            type: FlowRoutePointType::BranchEvaluate->value,
            sortOrder: 10,
            isStart: true,
            definition: [
                'branches' => [[
                    'conditions' => [[
                        'source' => 'contact_status',
                        'path' => 'key',
                        'operator' => 'in',
                        'values' => ['eligible', 'also_eligible'],
                    ]],
                    'target_flow_route_point_key' => 'eligible_wait',
                ]],
                'default_target_flow_route_point_key' => 'ineligible_wait',
                'on_no_match' => 'completed',
            ],
        );

        $eligibleWait = $this->point(
            flowRoute: $flowRoute,
            key: 'eligible_wait',
            type: FlowRoutePointType::Wait->value,
            sortOrder: 20,
            definition: ['days' => 1],
        );

        $ineligibleWait = $this->point(
            flowRoute: $flowRoute,
            key: 'ineligible_wait',
            type: FlowRoutePointType::Wait->value,
            sortOrder: 30,
            definition: ['days' => 2],
        );

        $branch->forceFill([
            'next_flow_route_point_id' => null,
        ])->save();

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'test.workflow_context',
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'source' => 'test',
            ],
        ]);

        return [
            'contact' => $contact,
            'contact_status' => $contactStatus,
            'workflow_profile' => $workflowProfile,
            'flow_route' => $flowRoute,
            'eligible_wait' => $eligibleWait,
            'ineligible_wait' => $ineligibleWait,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function point(
        FlowRoute $flowRoute,
        string $key,
        string $type,
        int $sortOrder,
        bool $isStart = false,
        array $definition = [],
    ): FlowRoutePoint {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'flow_route_capability_id' => null,
            'key' => $key,
            'type' => $type,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'description' => null,
            'sort_order' => $sortOrder,
            'is_start' => $isStart,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => $definition,
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }
}