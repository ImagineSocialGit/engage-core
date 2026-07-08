<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Actions\BuildTaskDigestsAction;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\ContactShow\ContactTasksShowDataProvider;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RuntimeTaskBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_task_action_allows_standalone_contactless_unassigned_tasks(): void
    {
        $task = app(CreateTaskAction::class)->handle([
            'title' => 'Review operations checklist',
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
        ]);

        $this->assertNull($task->related_type);
        $this->assertNull($task->related_id);
        $this->assertNull($task->assigned_to_type);
        $this->assertNull($task->assigned_to_id);
        $this->assertSame(Task::RESPONSIBLE_PARTY_INTERNAL, $task->responsible_party);
        $this->assertNull($task->responsible_type);
        $this->assertNull($task->responsible_id);
        $this->assertSame(Task::STATUS_OPEN, $task->status);
    }

    public function test_create_task_action_normalizes_related_contact_and_infers_responsible_contact(): void
    {
        $contact = Contact::factory()->create();

        $task = app(CreateTaskAction::class)->handle([
            'related_type' => Contact::class,
            'related_id' => $contact->id,
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'title' => 'Lead needs to upload bank statements',
        ]);

        $this->assertSame($contact->getMorphClass(), $task->related_type);
        $this->assertSame($contact->id, $task->related_id);
        $this->assertSame(Task::RESPONSIBLE_PARTY_CONTACT, $task->responsible_party);
        $this->assertSame($contact->getMorphClass(), $task->responsible_type);
        $this->assertSame($contact->id, $task->responsible_id);
    }

    public function test_create_task_action_keeps_assignment_separate_from_responsibility(): void
    {
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create();

        $task = app(CreateTaskAction::class)->handle([
            'related_type' => Contact::class,
            'related_id' => $contact->id,
            'assigned_to_id' => $teamMember->id,
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'title' => 'Lead needs to sign disclosures',
        ]);

        $this->assertSame($teamMember->getMorphClass(), $task->assigned_to_type);
        $this->assertSame($teamMember->id, $task->assigned_to_id);

        $this->assertSame(Task::RESPONSIBLE_PARTY_CONTACT, $task->responsible_party);
        $this->assertSame($contact->getMorphClass(), $task->responsible_type);
        $this->assertSame($contact->id, $task->responsible_id);
    }

    public function test_create_task_action_resolves_only_active_team_member_assignment_strategy(): void
    {
        $teamMember = TeamMember::factory()->create([
            'name' => 'Only Active Member',
        ]);

        TeamMember::factory()->inactive()->create();

        $task = app(CreateTaskAction::class)->handle([
            'title' => 'Strategy assigned task',
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
        ]);

        $this->assertSame($teamMember->getMorphClass(), $task->assigned_to_type);
        $this->assertSame($teamMember->id, $task->assigned_to_id);
    }

    public function test_create_task_from_template_action_applies_template_defaults_and_overrides(): void
    {
        $contact = Contact::factory()->create();

        $template = TaskTemplate::factory()->create([
            'key' => 'test.follow_up',
            'title' => 'Template follow up',
            'task_description' => 'Template body',
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'priority' => 'high',
            'due_offset_minutes' => 60,
        ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template, [
            'related_type' => Contact::class,
            'related_id' => $contact->id,
            'title' => 'Override title',
        ]);

        $this->assertSame('Override title', $task->title);
        $this->assertSame('Template body', $task->description);
        $this->assertSame(Task::RESPONSIBLE_PARTY_CONTACT, $task->responsible_party);
        $this->assertSame($contact->getMorphClass(), $task->responsible_type);
        $this->assertSame($contact->id, $task->responsible_id);
        $this->assertSame('high', $task->priority);
        $this->assertNotNull($task->due_at);
        $this->assertSame('test.follow_up', $task->meta['task_template']['key']);
    }

    public function test_task_preset_sync_preserves_customized_templates_without_force(): void
    {
        config()->set('presets.packages.test_package.groups.tasks', ['test_group']);
        config()->set('presets.tasks.groups.test_group', ['test.follow_up']);
        config()->set('presets.tasks.definitions', [
            'test.follow_up' => [
                'title' => 'Synced title',
                'description' => 'Synced description',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'due_offset_minutes' => 30,
                'source_version' => 'one',
            ],
        ]);

        TaskTemplate::factory()->customized()->create([
            'key' => 'test.follow_up',
            'group_key' => 'test_group',
            'title' => 'Customized title',
            'description' => 'Customized description',
        ]);

        $result = app(SyncTaskPresetsAction::class)->handle('test_package');

        $template = TaskTemplate::query()->where('key', 'test.follow_up')->firstOrFail();

        $this->assertSame('Customized title', $template->title);
        $this->assertSame(1, $result->customizedSkipped);
    }

    public function test_task_preset_sync_removes_stale_non_customized_templates(): void
    {
        config()->set('presets.packages.test_package.groups.tasks', ['test_group']);
        config()->set('presets.tasks.groups.test_group', ['test.keep']);
        config()->set('presets.tasks.definitions', [
            'test.keep' => [
                'title' => 'Keep this task',
                'description' => 'Kept description',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            ],
        ]);

        TaskTemplate::factory()->create([
            'key' => 'test.stale',
            'group_key' => 'test_group',
            'is_customized' => false,
        ]);

        $result = app(SyncTaskPresetsAction::class)->handle('test_package');

        $this->assertSame(1, $result->removed);
        $this->assertDatabaseMissing('task_templates', [
            'key' => 'test.stale',
        ]);
        $this->assertDatabaseHas('task_templates', [
            'key' => 'test.keep',
            'title' => 'Keep this task',
        ]);
    }

    public function test_task_preset_sync_overwrites_customized_templates_with_force(): void
    {
        config()->set('presets.packages.test_package.groups.tasks', ['test_group']);
        config()->set('presets.tasks.groups.test_group', ['test.follow_up']);
        config()->set('presets.tasks.definitions', [
            'test.follow_up' => [
                'title' => 'Synced title',
                'name' => 'Synced name',
                'description' => 'Synced description',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'due_offset_minutes' => 30,
                'source_version' => 'one',
            ],
        ]);

        TaskTemplate::factory()->customized()->create([
            'key' => 'test.follow_up',
            'group_key' => 'test_group',
            'title' => 'Customized title',
            'description' => 'Customized description',
        ]);

        $result = app(SyncTaskPresetsAction::class)->handle(
            presetKey: 'test_package',
            force: true,
        );

        $template = TaskTemplate::query()->where('key', 'test.follow_up')->firstOrFail();

        $this->assertSame('Synced title', $template->title);
        $this->assertSame('Synced description', $template->description);
        $this->assertFalse((bool) $template->is_customized);
        $this->assertNull($template->customized_at);
        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->customizedSkipped);
    }


    public function test_complete_task_action_completes_task_touches_related_contact_and_dispatches_event_once(): void
    {
        Event::fake([
            TaskCompleted::class,
        ]);

        Carbon::setTestNow('2026-07-08 10:00:00');

        $contact = Contact::factory()->create([
            'last_activity_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);

        $task = Task::factory()->relatedTo($contact)->create([
            'status' => Task::STATUS_CANCELED,
            'completed_at' => null,
            'canceled_at' => Carbon::parse('2026-07-07 09:00:00'),
            'canceled_reason' => 'Not needed yet.',
        ]);

        $completedTask = app(CompleteTaskAction::class)->handle($task);

        $this->assertSame(Task::STATUS_COMPLETED, $completedTask->status);
        $this->assertTrue($completedTask->completed_at->equalTo(Carbon::now()));
        $this->assertNull($completedTask->canceled_at);
        $this->assertNull($completedTask->canceled_reason);

        $this->assertTrue($contact->refresh()->last_activity_at->equalTo(Carbon::now()));

        Event::assertDispatched(
            TaskCompleted::class,
            fn (TaskCompleted $event): bool => $event->task->is($completedTask),
        );

        Carbon::setTestNow('2026-07-08 11:00:00');

        app(CompleteTaskAction::class)->handle($completedTask->refresh());

        Event::assertDispatchedTimes(TaskCompleted::class, 1);
    }

    public function test_complete_task_action_does_not_touch_non_contact_related_subjects(): void
    {
        Event::fake([
            TaskCompleted::class,
        ]);

        Carbon::setTestNow('2026-07-08 10:00:00');

        $task = Task::factory()->create([
            'related_type' => 'not_a_contact',
            'related_id' => 999,
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        $completedTask = app(CompleteTaskAction::class)->handle($task);

        $this->assertSame(Task::STATUS_COMPLETED, $completedTask->status);
        $this->assertTrue($completedTask->completed_at->equalTo(Carbon::now()));

        Event::assertDispatched(TaskCompleted::class);
    }

    public function test_task_completed_automation_payload_includes_responsibility_fields(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();

        $task = Task::factory()
            ->relatedTo($contact)
            ->completed()
            ->create([
                'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
                'responsible_type' => $contact->getMorphClass(),
                'responsible_id' => $contact->id,
            ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            function (AutomationEventRecorded $event) use ($task, $contact): bool {
                return $event->event->eventKey === TaskCompleted::NAME
                    && $event->event->contactId === $contact->id
                    && $event->event->subjectType === $task->getMorphClass()
                    && $event->event->subjectId === $task->id
                    && $event->event->payload['task']['responsible_party'] === Task::RESPONSIBLE_PARTY_CONTACT
                    && $event->event->payload['task']['responsible_type'] === $contact->getMorphClass()
                    && $event->event->payload['task']['responsible_id'] === $contact->id;
            },
        );
    }

    public function test_task_completed_contact_id_resolves_from_responsible_contact_when_related_is_contactless(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();

        $task = Task::factory()
            ->unrelated()
            ->completed()
            ->create([
                'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
                'responsible_type' => $contact->getMorphClass(),
                'responsible_id' => $contact->id,
            ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            fn (AutomationEventRecorded $event): bool => $event->event->contactId === $contact->id,
        );
    }

    public function test_contact_show_task_lookup_is_safe_for_contact_class_and_morph_alias(): void
    {
        $originalMorphMap = Relation::morphMap();

        Relation::morphMap([
            'contact' => Contact::class,
        ], false);

        try {
            $contact = Contact::factory()->create();

            $classStoredTask = Task::factory()->create([
                'related_type' => Contact::class,
                'related_id' => $contact->id,
                'title' => 'Stored with class name',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            ]);

            $aliasStoredTask = Task::factory()->create([
                'related_type' => $contact->getMorphClass(),
                'related_id' => $contact->id,
                'title' => 'Stored with morph alias',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            ]);

            $data = app(ContactTasksShowDataProvider::class)->dataFor($contact);

            $this->assertTrue($data['tasks']->contains($classStoredTask));
            $this->assertTrue($data['tasks']->contains($aliasStoredTask));
        } finally {
            Relation::morphMap($originalMorphMap, false);
        }
    }

    public function test_unassigned_tasks_do_not_create_digest_entries(): void
    {
        Task::factory()->create([
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'due_at' => now(),
        ]);

        $digests = app(BuildTaskDigestsAction::class)->handle(
            BuildTaskDigestsAction::FREQUENCY_DAILY,
        );

        $this->assertCount(0, $digests);
    }

    public function test_task_completed_automation_meta_includes_full_flow_route_provenance(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();

        $flowRoute = $this->createFlowRouteProvenance($contact);

        $task = Task::factory()
            ->relatedTo($contact)
            ->completed()
            ->create([
                'flow_route_progress_id' => $flowRoute['progress']->getKey(),
                'flow_route_plan_id' => $flowRoute['plan']->getKey(),
                'flow_route_plan_item_id' => $flowRoute['plan_item']->getKey(),
                'flow_route_progress_item_id' => $flowRoute['progress_item']->getKey(),
                'flow_route_id' => $flowRoute['flow_route']->getKey(),
                'flow_route_point_id' => $flowRoute['flow_route_point']->getKey(),
                'flow_route_capability_id' => $flowRoute['capability']->getKey(),
            ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            function (AutomationEventRecorded $event) use ($flowRoute): bool {
                return data_get($event->event->meta, 'flow_route_progress_id') === $flowRoute['progress']->getKey()
                    && data_get($event->event->meta, 'flow_route_plan_id') === $flowRoute['plan']->getKey()
                    && data_get($event->event->meta, 'flow_route_plan_item_id') === $flowRoute['plan_item']->getKey()
                    && data_get($event->event->meta, 'flow_route_progress_item_id') === $flowRoute['progress_item']->getKey()
                    && data_get($event->event->meta, 'flow_route_id') === $flowRoute['flow_route']->getKey()
                    && data_get($event->event->meta, 'flow_route_point_id') === $flowRoute['flow_route_point']->getKey()
                    && data_get($event->event->meta, 'flow_route_capability_id') === $flowRoute['capability']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_progress_id') === $flowRoute['progress']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_plan_id') === $flowRoute['plan']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_plan_item_id') === $flowRoute['plan_item']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_progress_item_id') === $flowRoute['progress_item']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_id') === $flowRoute['flow_route']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_point_id') === $flowRoute['flow_route_point']->getKey()
                    && data_get($event->event->meta, 'flow_route.flow_route_capability_id') === $flowRoute['capability']->getKey();
            },
        );
    }

    private function createFlowRouteProvenance(Contact $contact): array
    {
        $status = \App\Modules\Core\Models\ContactStatus::query()->create([
            'key' => 'test_flow_route_status_'.uniqid(),
            'name' => 'Test Flow Route Status',
            'is_active' => true,
        ]);

        $flowRoute = \App\Modules\FlowRoutes\Models\FlowRoute::query()->create([
            'key' => 'test_flow_route_'.uniqid(),
            'contact_status_id' => $status->getKey(),
            'name' => 'Test Flow Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $capability = \App\Modules\FlowRoutes\Models\FlowRouteCapability::query()->create([
            'key' => 'test_capability_'.uniqid(),
            'module_key' => 'flow_routes',
            'capability_type' => \App\Modules\FlowRoutes\Models\FlowRouteCapability::TYPE_ACTION,
            'point_type' => \App\Modules\FlowRoutes\Models\Point::TYPE_NOOP,
            'handler_key' => 'noop',
            'event_key' => null,
            'action_key' => 'noop',
            'name' => 'Test Capability',
            'description' => null,
            'category' => 'test',
            'surface' => 'test',
            'supported_subjects' => [],
            'required_modules' => [],
            'input_schema' => [],
            'output_schema' => [],
            'available_fields' => [],
            'defaults' => [],
            'is_active' => true,
            'source' => 'test',
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $point = \App\Modules\FlowRoutes\Models\Point::query()->create([
            'key' => 'test_point_'.uniqid(),
            'type' => \App\Modules\FlowRoutes\Models\Point::TYPE_NOOP,
            'name' => 'Test Point',
            'description' => null,
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $flowRoutePoint = \App\Modules\FlowRoutes\Models\FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $point->getKey(),
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_point',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $progress = \App\Modules\FlowRoutes\Models\ContactFlowRouteProgress::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
            'contact_workflow_profile_id' => null,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $flowRoutePoint->getKey(),
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'meta' => [],
        ]);

        $plan = \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_id' => $contact->getKey(),
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::STATUS_ACTIVE,
            'source' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::SOURCE_TEMPLATE,
            'flow_route_version' => 1,
            'snapshot_at' => now(),
            'started_at' => now(),
            'route_snapshot' => [],
            'meta' => [],
        ]);

        $planItem = \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_flow_route_plan_id' => $plan->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'point_id' => $point->getKey(),
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_plan_item',
            'point_type' => $point->type,
            'sort_order' => 10,
            'sequence' => 1,
            'attempt' => 1,
            'source' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::SOURCE_TEMPLATE,
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::STATUS_COMPLETED,
            'definition_snapshot' => [],
            'settings_snapshot' => [],
            'cancel_conditions_snapshot' => [],
            'started_at' => now(),
            'completed_at' => now(),
            'meta' => [],
        ]);

        $progressItem = \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_flow_route_plan_id' => $plan->getKey(),
            'contact_flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'point_id' => $point->getKey(),
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_progress_item',
            'point_type' => $point->type,
            'sequence' => 1,
            'attempt' => 1,
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'meta' => [],
        ]);

        return [
            'progress' => $progress,
            'plan' => $plan,
            'plan_item' => $planItem,
            'progress_item' => $progressItem,
            'flow_route' => $flowRoute,
            'flow_route_point' => $flowRoutePoint,
            'capability' => $capability,
        ];
    }

}
