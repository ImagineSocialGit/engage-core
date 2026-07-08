<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\WaitPointHandler;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowRoutePointExecutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_resolves_registered_point_handlers(): void
    {
        $registry = new PointHandlerRegistry([
            new NoopPointHandler(),
            new WaitPointHandler(),
        ]);

        $this->assertTrue($registry->has(Point::TYPE_NOOP));
        $this->assertTrue($registry->has(Point::TYPE_WAIT));
        $this->assertInstanceOf(NoopPointHandler::class, $registry->resolve(Point::TYPE_NOOP));
        $this->assertInstanceOf(WaitPointHandler::class, $registry->resolve(Point::TYPE_WAIT));
    }

    public function test_noop_point_completes_and_advances_to_next_point(): void
    {
        $setup = $this->createProgressWithPoints([
            Point::TYPE_NOOP,
            Point::TYPE_WAIT,
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $setup['progress']->status);
        $this->assertSame($setup['flow_route_points'][1]->getKey(), $setup['progress']->current_flow_route_point_id);
        $this->assertSame(Point::TYPE_WAIT, $setup['progress']->currentFlowRoutePoint->point->type);
    }

    public function test_noop_point_completes_route_when_no_next_point_exists(): void
    {
        $setup = $this->createProgressWithPoints([
            Point::TYPE_NOOP,
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $setup['progress']->status);
        $this->assertNull($setup['progress']->current_flow_route_point_id);
        $this->assertNull($setup['progress']->resume_at);
        $this->assertNull($setup['progress']->waiting_event_key);
        $this->assertNotNull($setup['progress']->completed_at);
    }

    public function test_wait_point_returns_waiting_and_does_not_advance(): void
    {
        Queue::fake();

        $setup = $this->createProgressWithPoints([
            Point::TYPE_WAIT,
            Point::TYPE_NOOP,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'seconds' => 300,
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_WAITING, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $setup['progress']->status);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $setup['progress']->current_flow_route_point_id);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $setup['progress']->waitingFlowRoutePointId());
        $this->assertNotNull($setup['progress']->resume_at);
        $this->assertNotNull($setup['progress']->waitingResumeAt());
        $this->assertNull($setup['progress']->waiting_event_key);

        Queue::assertPushed(ResumeFlowRouteProgressJob::class);
    }

    public function test_unknown_point_type_fails_progress(): void
    {
        $setup = $this->createProgressWithPoints([
            'future_handler_type',
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_FAILED, $result->status);
        $this->assertSame('point_handler_not_registered', $result->reason);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_FAILED, $setup['progress']->status);
        $this->assertNull($setup['progress']->resume_at);
        $this->assertNull($setup['progress']->waiting_event_key);
        $this->assertSame('point_handler_not_registered', $setup['progress']->failure_reason);
        $this->assertNotNull($setup['progress']->failed_at);
    }

    public function test_send_message_point_skips_when_channel_is_not_available_for_route_send_message_points(): void
    {
        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.route_send_message_points', false);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:webinar_nurture' => true,
        ]);

        config()->set('messaging.sms.marketing.webinar_nurture.route_test', [
            [
                'dispatch_key' => 'route_send_message_test',
                'channel' => 'sms',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'timing' => 'immediate',
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'Route SMS test',
                ],
            ],
        ]);

        $setup = $this->createProgressWithPoints([
            Point::TYPE_SEND_MESSAGE,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'channel' => 'sms',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'dispatch_keys' => ['route_send_message_test'],
                'on_no_messages' => 'skipped',
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('send_message_channel_unavailable', $result->reason);
        $this->assertSame('sms', data_get($result->meta, 'send_message_definition.channel'));

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $setup['progress']->status);
        $this->assertNull($setup['progress']->current_flow_route_point_id);
        $this->assertSame(0, ScheduledMessage::query()->count());
    }



    public function test_create_task_point_assigns_to_only_active_team_member(): void
    {
        $teamMember = TeamMember::factory()->create([
            'name' => 'Only Member',
        ]);

        TeamMember::factory()->inactive()->create();

        $setup = $this->createProgressWithPoints([
            Point::TYPE_CREATE_TASK,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'title' => 'The prospect task',
                'assigned_to' => 'only_active_team_member',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('task_created', $result->reason);

        $task = Task::query()->where('title', 'The prospect task')->firstOrFail();

        $this->assertSame($teamMember->getMorphClass(), $task->assigned_to_type);
        $this->assertSame($teamMember->id, $task->assigned_to_id);
    }

    public function test_inline_create_task_point_due_offset_minutes_sets_due_at(): void
    {
        $setup = $this->createProgressWithPoints([
            Point::TYPE_CREATE_TASK,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'title' => 'Due offset task',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'due_offset_minutes' => 45,
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('task_created', $result->reason);

        $task = Task::query()->where('title', 'Due offset task')->firstOrFail();

        $this->assertNotNull($task->due_at);
        $this->assertTrue($task->due_at->between(
            now()->addMinutes(44),
            now()->addMinutes(46),
        ));
    }

    public function test_create_task_point_can_create_task_from_template_key(): void
    {
        TaskTemplate::factory()->create([
            'key' => 'route.follow_up',
            'title' => 'Template route task',
            'task_description' => 'Created from template.',
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'priority' => 'high',
            'due_offset_minutes' => 30,
        ]);

        $setup = $this->createProgressWithPoints([
            Point::TYPE_CREATE_TASK,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'task_template_key' => 'route.follow_up',
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('task_created', $result->reason);

        $task = Task::query()->where('title', 'Template route task')->firstOrFail();

        $this->assertSame('Created from template.', $task->description);
        $this->assertSame('high', $task->priority);
        $this->assertSame('route.follow_up', $task->meta['task_template']['key']);

        $this->assertSame($setup['progress']->getKey(), $task->flow_route_progress_id);
        $this->assertSame($setup['flow_route']->getKey(), $task->flow_route_id);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $task->flow_route_point_id);
        $this->assertNotNull($task->flow_route_plan_id);
        $this->assertNotNull($task->flow_route_plan_item_id);
        $this->assertNotNull($task->flow_route_progress_item_id);
    }

    public function test_enroll_campaign_point_stores_route_provenance_on_enrollment_and_scheduled_message(): void
    {
        Queue::fake();

        config()->set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'campaigns' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar' => true,
            ],
        ]);

        config()->set('messaging.email.marketing.webinar.campaigns.route_campaign.steps.1.variants.email', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'to' => '{email}',
                'subject' => 'Route campaign',
                'body' => 'Route campaign body',
            ],
        ]);

        $campaign = Campaign::query()->create([
            'key' => 'route_campaign',
            'name' => 'Route Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $step = CampaignStep::query()->create([
            'campaign_id' => $campaign->getKey(),
            'step_number' => 1,
            'name' => 'Step 1',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],
            ],
            'meta' => [
                'type' => 'message',
            ],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->getKey(),
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'source_config_path' => 'messaging.email.marketing.webinar.campaigns.route_campaign.steps.1.variants.email',
            'meta' => [],
        ]);

        $setup = $this->createProgressWithPoints([
            Point::TYPE_ENROLL_CAMPAIGN,
        ]);

        MessageConsent::query()->create([
            'contact_id' => $setup['progress']->contact_id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'campaign_key' => 'route_campaign',
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('campaign_enrolled', $result->reason);

        $enrollment = CampaignEnrollment::query()->firstOrFail();
        $scheduledMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame($setup['progress']->getKey(), $enrollment->flow_route_progress_id);
        $this->assertSame($setup['flow_route']->getKey(), $enrollment->flow_route_id);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $enrollment->flow_route_point_id);
        $this->assertNotNull($enrollment->flow_route_plan_id);
        $this->assertNotNull($enrollment->flow_route_plan_item_id);
        $this->assertNotNull($enrollment->flow_route_progress_item_id);

        $this->assertSame($enrollment->flow_route_progress_id, $scheduledMessage->flow_route_progress_id);
        $this->assertSame($enrollment->flow_route_plan_id, $scheduledMessage->flow_route_plan_id);
        $this->assertSame($enrollment->flow_route_plan_item_id, $scheduledMessage->flow_route_plan_item_id);
        $this->assertSame($enrollment->flow_route_progress_item_id, $scheduledMessage->flow_route_progress_item_id);
        $this->assertSame($enrollment->flow_route_id, $scheduledMessage->flow_route_id);
        $this->assertSame($enrollment->flow_route_point_id, $scheduledMessage->flow_route_point_id);
    }

    public function test_automation_event_route_can_change_contact_workflow_status(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();

        $targetStatus = ContactStatus::query()->create([
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
            'is_active' => true,
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'webinar_attended_status_transition',
            'contact_status_id' => null,
            'name' => 'Webinar Attended Status Transition',
            'description' => null,
            'version' => 1,
            'trigger_type' => 'automation_event',
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $point = Point::query()->create([
            'key' => 'change_status_to_attended_webinar',
            'type' => Point::TYPE_CHANGE_STATUS,
            'name' => 'Change Status to Attended Webinar',
            'description' => null,
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $point->getKey(),
            'key' => 'change_status_to_attended_webinar',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'contact_status_key' => 'attended_webinar',
                'reason' => 'webinar_attended_event',
                'on_same_status' => 'skipped',
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'source' => 'test',
            ],
        ]);

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: 'webinar.attended',
                contactId: $contact->getKey(),
                subjectType: 'webinar_registration',
                subjectId: 123,
                occurredAt: now(),
                payload: [
                    'webinar_registration' => [
                        'id' => 123,
                    ],
                ],
            ),
        );

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $targetStatus->getKey(),
        ]);

        $progress = ContactFlowRouteProgress::query()->first();

        $this->assertNotNull($progress);
        $this->assertSame($contact->getKey(), $progress->contact_id);
        $this->assertSame($flowRoute->getKey(), $progress->flow_route_id);
        $this->assertSame('webinar.attended', $progress->meta['started_from_automation_event']['name'] ?? null);

        Event::assertDispatched(ContactWorkflowStatusChanged::class);
    }

    /**
     * @param array<int, string> $types
     * @return array{
     *     progress: ContactFlowRouteProgress,
     *     flow_route: FlowRoute,
     *     flow_route_points: array<int, FlowRoutePoint>
     * }
     */
    private function createProgressWithPoints(array $types): array
    {
        $contactId = DB::table('contacts')->insertGetId([
            'first_name' => 'FlowRoute',
            'last_name' => 'Test',
            'name' => 'FlowRoute Test',
            'email' => 'flowroute-test-'.uniqid().'@example.com',
            'phone' => null,
            'source' => 'test',
            'subsource' => null,
            'last_contacted_at' => null,
            'last_activity_at' => now(),
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contactStatusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'testing-'.uniqid(),
            'name' => 'Testing',
            'description' => null,
            'category' => null,
            'color' => null,
            'is_core' => false,
            'is_active' => true,
            'sort_order' => 1,
            'source_version' => null,
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workflowProfile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contactId,
            'contact_status_id' => $contactStatusId,
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'testing-flow-route-'.uniqid(),
            'contact_status_id' => $contactStatusId,
            'name' => 'Testing FlowRoute',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => null,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $flowRoutePoints = [];

        foreach ($types as $index => $type) {
            $point = Point::query()->create([
                'key' => 'test-'.$type.'-'.$index.'-'.uniqid(),
                'type' => $type,
                'name' => ucfirst(str_replace('_', ' ', $type)),
                'description' => null,
                'default_definition' => [],
                'default_settings' => [],
                'is_active' => true,
                'source_version' => null,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => [],
            ]);

            $flowRoutePoints[] = FlowRoutePoint::query()->create([
                'flow_route_id' => $flowRoute->getKey(),
                'point_id' => $point->getKey(),
                'key' => 'route-point-'.$index.'-'.uniqid(),
                'sort_order' => ($index + 1) * 10,
                'is_start' => $index === 0,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => [],
                'settings' => [],
                'cancel_conditions' => [],
                'source_version' => null,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => [],
            ]);
        }

        foreach ($flowRoutePoints as $index => $flowRoutePoint) {
            $nextFlowRoutePoint = $flowRoutePoints[$index + 1] ?? null;

            $flowRoutePoint->forceFill([
                'next_flow_route_point_id' => $nextFlowRoutePoint?->getKey(),
            ])->save();
        }

        $progress = ContactFlowRouteProgress::query()->create([
            'contact_id' => $contactId,
            'contact_status_id' => $contactStatusId,
            'contact_workflow_profile_id' => $workflowProfile->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $flowRoutePoints[0]->getKey(),
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

        return [
            'progress' => $progress,
            'flow_route' => $flowRoute,
            'flow_route_points' => $flowRoutePoints,
        ];
    }
}
