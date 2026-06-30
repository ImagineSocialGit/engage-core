<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteConditionBranchPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_condition_point_completes_and_advances_when_condition_passes(): void
    {
        $scenario = $this->scenario();

        $conditionPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_CONDITION,
            sortOrder: 10,
            key: 'condition',
            isStart: true,
            definition: [
                'conditions' => [
                    [
                        'source' => 'contact_status',
                        'path' => 'key',
                        'operator' => 'equals',
                        'value' => 'lead',
                    ],
                ],
                'on_pass' => PointExecutionResult::STATUS_COMPLETED,
                'on_fail' => PointExecutionResult::STATUS_BLOCKED,
            ],
        );

        $nextPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'next',
        );

        $conditionPoint->forceFill([
            'next_flow_route_point_id' => $nextPoint->getKey(),
        ])->save();

        $progress = $this->progress($scenario, $conditionPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('condition_point_passed', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($nextPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_condition_point_blocks_when_condition_fails_and_on_fail_is_blocked(): void
    {
        $scenario = $this->scenario();

        $conditionPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_CONDITION,
            sortOrder: 10,
            key: 'condition',
            isStart: true,
            definition: [
                'conditions' => [
                    [
                        'source' => 'contact_status',
                        'path' => 'key',
                        'operator' => 'equals',
                        'value' => 'not-lead',
                    ],
                ],
                'on_pass' => PointExecutionResult::STATUS_COMPLETED,
                'on_fail' => PointExecutionResult::STATUS_BLOCKED,
            ],
        );

        $nextPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'next',
        );

        $conditionPoint->forceFill([
            'next_flow_route_point_id' => $nextPoint->getKey(),
        ])->save();

        $progress = $this->progress($scenario, $conditionPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_BLOCKED, $result->status);
        $this->assertSame('condition_point_failed', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($conditionPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_condition_point_skips_and_advances_when_condition_fails_and_on_fail_is_skipped(): void
    {
        $scenario = $this->scenario();

        $conditionPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_CONDITION,
            sortOrder: 10,
            key: 'condition',
            isStart: true,
            definition: [
                'conditions' => [
                    [
                        'source' => 'contact_status',
                        'path' => 'key',
                        'operator' => 'equals',
                        'value' => 'not-lead',
                    ],
                ],
                'on_pass' => PointExecutionResult::STATUS_COMPLETED,
                'on_fail' => PointExecutionResult::STATUS_SKIPPED,
            ],
        );

        $nextPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'next',
        );

        $conditionPoint->forceFill([
            'next_flow_route_point_id' => $nextPoint->getKey(),
        ])->save();

        $progress = $this->progress($scenario, $conditionPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('condition_point_failed', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($nextPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_branch_evaluate_point_jumps_to_matching_target_flow_route_point_key(): void
    {
        $scenario = $this->scenario();

        $branchPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_BRANCH_EVALUATE,
            sortOrder: 10,
            key: 'branch',
            isStart: true,
            definition: [
                'branches' => [
                    [
                        'conditions' => [
                            [
                                'source' => 'contact',
                                'path' => 'email',
                                'operator' => 'equals',
                                'value' => 'branch-test@example.com',
                            ],
                        ],
                        'target_flow_route_point_key' => 'target',
                    ],
                ],
                'on_no_match' => PointExecutionResult::STATUS_BLOCKED,
            ],
        );

        $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'non_target',
        );

        $targetPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 30,
            key: 'target',
        );

        $progress = $this->progress($scenario, $branchPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('branch_matched', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($targetPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_branch_evaluate_point_uses_default_target_when_no_branch_matches(): void
    {
        $scenario = $this->scenario();

        $branchPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_BRANCH_EVALUATE,
            sortOrder: 10,
            key: 'branch',
            isStart: true,
            definition: [
                'branches' => [
                    [
                        'conditions' => [
                            [
                                'source' => 'contact',
                                'path' => 'email',
                                'operator' => 'equals',
                                'value' => 'does-not-match@example.com',
                            ],
                        ],
                        'target_flow_route_point_key' => 'non_target',
                    ],
                ],
                'default_target_flow_route_point_key' => 'default_target',
                'on_no_match' => PointExecutionResult::STATUS_BLOCKED,
            ],
        );

        $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'non_target',
        );

        $defaultTargetPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 30,
            key: 'default_target',
        );

        $progress = $this->progress($scenario, $branchPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('branch_default_target_selected', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($defaultTargetPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_branch_evaluate_point_blocks_when_no_branch_matches_and_no_default_target_exists(): void
    {
        $scenario = $this->scenario();

        $branchPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_BRANCH_EVALUATE,
            sortOrder: 10,
            key: 'branch',
            isStart: true,
            definition: [
                'branches' => [
                    [
                        'conditions' => [
                            [
                                'source' => 'contact',
                                'path' => 'email',
                                'operator' => 'equals',
                                'value' => 'does-not-match@example.com',
                            ],
                        ],
                        'target_flow_route_point_key' => 'non_target',
                    ],
                ],
                'on_no_match' => PointExecutionResult::STATUS_BLOCKED,
            ],
        );

        $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_NOOP,
            sortOrder: 20,
            key: 'non_target',
        );

        $progress = $this->progress($scenario, $branchPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_BLOCKED, $result->status);
        $this->assertSame('branch_no_match', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($branchPoint->id, $progress->current_flow_route_point_id);
    }

    public function test_unsupported_condition_fails_safely(): void
    {
        $scenario = $this->scenario();

        $conditionPoint = $this->routePoint(
            flowRoute: $scenario['flow_route'],
            type: Point::TYPE_CONDITION,
            sortOrder: 10,
            key: 'condition',
            isStart: true,
            definition: [
                'conditions' => [
                    [
                        'source' => 'unsupported_source',
                        'path' => 'anything',
                        'operator' => 'equals',
                        'value' => 'anything',
                    ],
                ],
                'on_pass' => PointExecutionResult::STATUS_COMPLETED,
                'on_fail' => PointExecutionResult::STATUS_FAILED,
            ],
        );

        $progress = $this->progress($scenario, $conditionPoint);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(PointExecutionResult::STATUS_FAILED, $result->status);
        $this->assertSame('condition_point_failed', $result->reason);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_FAILED, $progress->status);
        $this->assertNull($progress->resume_at);
        $this->assertNull($progress->waiting_event_key);
        $this->assertSame('condition_point_failed', $progress->failure_reason);
    }

    /**
     * @return array{
     *     contact: Contact,
     *     contact_status: ContactStatus,
     *     workflow_profile: ContactWorkflowProfile,
     *     flow_route: FlowRoute
     * }
     */
    private function scenario(): array
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
            'description' => null,
            'category' => 'sales',
            'color' => null,
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 1,
            'source_version' => null,
            'meta' => [],
        ]);

        $contact = Contact::query()->create([
            'first_name' => 'Branch',
            'last_name' => 'Test',
            'name' => 'Branch Test',
            'email' => 'branch-test@example.com',
            'phone' => '15555550123',
            'source' => 'test',
            'subsource' => null,
            'last_contacted_at' => null,
            'last_activity_at' => now(),
            'meta' => [],
        ]);

        $workflowProfile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->id,
            'contact_status_id' => $contactStatus->id,
            'last_status_changed_at' => now(),
            'meta' => [
                'test' => true,
            ],
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'lead_route',
            'contact_status_id' => $contactStatus->id,
            'name' => 'Lead Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        return [
            'contact' => $contact,
            'contact_status' => $contactStatus,
            'workflow_profile' => $workflowProfile,
            'flow_route' => $flowRoute,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    private function routePoint(
        FlowRoute $flowRoute,
        string $type,
        int $sortOrder,
        string $key,
        bool $isStart = false,
        array $definition = [],
        array $settings = [],
    ): FlowRoutePoint {
        $point = Point::query()->create([
            'key' => $type.'-'.$sortOrder.'-'.uniqid(),
            'type' => $type,
            'name' => ucfirst(str_replace('_', ' ', $type)).' '.$sortOrder,
            'description' => null,
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        return FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->id,
            'point_id' => $point->id,
            'key' => $key,
            'sort_order' => $sortOrder,
            'is_start' => $isStart,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => $definition,
            'settings' => $settings,
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }

    /**
     * @param array{
     *     contact: Contact,
     *     contact_status: ContactStatus,
     *     workflow_profile: ContactWorkflowProfile,
     *     flow_route: FlowRoute
     * } $scenario
     */
    private function progress(array $scenario, FlowRoutePoint $currentPoint): ContactFlowRouteProgress
    {
        return ContactFlowRouteProgress::query()->create([
            'contact_id' => $scenario['contact']->id,
            'contact_status_id' => $scenario['contact_status']->id,
            'contact_workflow_profile_id' => $scenario['workflow_profile']->id,
            'flow_route_id' => $scenario['flow_route']->id,
            'current_flow_route_point_id' => $currentPoint->id,
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'meta' => [],
        ]);
    }
}