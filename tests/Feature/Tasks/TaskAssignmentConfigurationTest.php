<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\CoreTeamRoundRobinTaskAssignmentStrategyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAssignmentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('modules.enabled', ['tasks']);
    }

    public function test_operator_can_create_a_template_with_business_timing_and_round_robin_assignment(): void
    {
        $actor = User::factory()->create();
        $team = Team::query()->create(['name' => 'Sales', 'is_active' => true]);
        $team->users()->attach($actor);

        $this->actingAs($actor)->post(route('crm.tasks.templates.store'), [
            'name' => 'Review new inquiry',
            'title' => 'Review the new inquiry',
            'description' => 'Make sure the inquiry receives a human response.',
            'task_description' => 'Review the request and decide the next step.',
            'priority' => 'high',
            'due_timing' => 'after',
            'due_offset_value' => 1,
            'due_offset_unit' => 'business_days',
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assignment_mode' => 'team_round_robin',
            'assigned_team_id' => $team->getKey(),
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $template = TaskTemplate::query()->where('name', 'Review new inquiry')->sole();

        $this->assertSame(TaskTemplate::SOURCE_MANUAL, $template->source);
        $this->assertSame(CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX.$team->getKey(), $template->assigned_to_strategy);
        $this->assertSame('business_days', data_get($template->meta, 'timing.unit'));
        $this->assertNull($template->due_offset_minutes);
    }

    public function test_round_robin_template_rotates_created_tasks_across_active_team_members(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $team = Team::query()->create(['name' => 'Intake', 'is_active' => true]);
        $team->users()->attach([$first->getKey(), $second->getKey()]);
        $template = TaskTemplate::factory()->create([
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'assigned_to_strategy' => CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX.$team->getKey(),
        ]);

        $one = app(CreateTaskFromTemplateAction::class)->handle($template);
        $two = app(CreateTaskFromTemplateAction::class)->handle($template);

        $this->assertSame((int) $first->getKey(), $one->assigned_to_id);
        $this->assertSame((int) $second->getKey(), $two->assigned_to_id);
    }

    public function test_task_assignment_can_be_changed_or_removed(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to_type' => null, 'assigned_to_id' => null]);
        $key = $assignee->getMorphClass().':'.$assignee->getKey();

        $this->actingAs($actor)
            ->patch(route('crm.tasks.assignment.update', $task), ['assignee_key' => $key])
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame($assignee->getMorphClass(), $task->assigned_to_type);
        $this->assertSame((int) $assignee->getKey(), $task->assigned_to_id);

        $this->actingAs($actor)
            ->patch(route('crm.tasks.assignment.update', $task), ['assignee_key' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($task->refresh()->assigned_to_id);
    }

    public function test_business_day_timing_skips_the_weekend(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 15:00:00', 'UTC'));
        $template = TaskTemplate::factory()->create([
            'due_offset_minutes' => null,
            'meta' => ['timing' => ['mode' => 'after', 'value' => 1, 'unit' => 'business_days']],
        ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template);

        $this->assertSame('2026-09-14', $task->due_at?->utc()->format('Y-m-d'));
        CarbonImmutable::setTestNow();
    }
}