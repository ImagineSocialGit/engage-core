<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Tasks\Automation\TasksAutomationPointAuthoringContributor;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTemplateWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_workspace_exposes_the_tasks_owned_list_and_editor(): void
    {
        config()->set('modules.enabled', ['tasks']);

        $user = User::factory()->create();
        $template = TaskTemplate::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.tasks.templates.index'))
            ->assertOk()
            ->assertViewIs('crm.tasks.templates.index')
            ->assertViewHas('templates', fn ($templates): bool => $templates->count() === 1);

        $this->actingAs($user)
            ->get(route('crm.tasks.templates.edit', $template))
            ->assertOk()
            ->assertViewIs('crm.tasks.templates.edit')
            ->assertViewHas('taskTemplate', fn (TaskTemplate $viewTemplate): bool => (
                $viewTemplate->is($template)
            ));
    }

    public function test_template_update_marks_the_definition_customized(): void
    {
        config()->set('modules.enabled', ['tasks']);

        $user = User::factory()->create();
        $template = TaskTemplate::factory()->create([
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('crm.tasks.templates.update', $template), [
                'name' => 'Buying Intent Follow-Up',
                'title' => 'Call the contact about their reply',
                'description' => 'Follow up after a buying-intent response.',
                'task_description' => 'Review the reply and call the contact.',
                'priority' => 'high',
                'due_offset_minutes' => 30,
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'is_active' => true,
            ])
            ->assertRedirect(route('crm.tasks.templates.edit', $template));

        $template->refresh();

        $this->assertSame('Buying Intent Follow-Up', $template->name);
        $this->assertSame('Call the contact about their reply', $template->title);
        $this->assertSame('Review the reply and call the contact.', $template->task_description);
        $this->assertSame('high', $template->priority);
        $this->assertSame(30, $template->due_offset_minutes);
        $this->assertTrue($template->is_active);
        $this->assertTrue($template->is_customized);
        $this->assertNotNull($template->customized_at);
    }

    public function test_create_task_authoring_exposes_the_selected_template_as_an_owned_resource(): void
    {
        $template = TaskTemplate::factory()->create([
            'key' => 'buying_intent_follow_up',
            'priority' => 'high',
            'due_offset_minutes' => 30,
        ]);

        $fields = app(TasksAutomationPointAuthoringContributor::class)->fields(
            pointType: 'create_task',
            definition: ['task_template_key' => $template->key],
            context: new AutomationPointAuthoringContext(),
        );

        $resource = collect($fields)->firstWhere('type', 'resource');

        $this->assertIsArray($resource);
        $this->assertSame(
            route('crm.tasks.templates.edit', $template),
            $resource['action_url'],
        );
        $this->assertSame('_blank', $resource['action_target']);
        $this->assertEqualsCanonicalizing(
            ['Priority', 'Due', 'Assigned to', 'Responsible party'],
            array_keys($resource['details']),
        );
    }
}