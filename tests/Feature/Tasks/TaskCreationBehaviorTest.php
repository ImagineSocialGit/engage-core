<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TaskCreationBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_only_creation_allows_standalone_unassigned_manual_task(): void
    {
        $action = new CreateTaskAction(
            assignmentStrategies: new TaskAssignmentStrategyResolver([]),
            contactLinks: new TaskContactLinkResolver(),
        );

        $task = $action->handle([
            'title' => 'Review operations checklist',
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
        ]);

        $this->assertSame(Task::SOURCE_MANUAL, $task->source);
        $this->assertSame(Task::STATUS_OPEN, $task->status);
        $this->assertNull($task->assigned_to_type);
        $this->assertNull($task->assigned_to_id);
        $this->assertSame(Task::RESPONSIBLE_PARTY_INTERNAL, $task->responsible_party);
        $this->assertCount(0, $task->links);
    }

    public function test_automation_created_task_requires_template_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Automation-created Tasks must be template-backed.');

        app(CreateTaskAction::class)->handle([
            'source' => Task::SOURCE_MODULE,
            'title' => 'Invalid automation-created task',
        ]);
    }

    public function test_template_backed_task_can_be_created_manually(): void
    {
        $template = TaskTemplate::factory()->create([
            'key' => 'test.manual_template_task',
            'title' => 'Manual template task',
        ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template, [
            'source' => Task::SOURCE_MANUAL,
        ]);

        $this->assertSame(Task::SOURCE_MANUAL, $task->source);
        $this->assertSame($template->getKey(), $task->task_template_id);
        $this->assertSame($template->key, $task->task_template_key);
    }

    public function test_template_creation_resolves_current_contact_link_and_infers_responsible_contact(): void
    {
        $contact = Contact::factory()->create();

        $template = TaskTemplate::factory()
            ->currentContactSubject()
            ->contactResponsible()
            ->create([
                'key' => 'test.follow_up',
                'title' => 'Template follow up',
                'task_description' => 'Template body',
                'priority' => 'high',
                'due_offset_minutes' => 60,
            ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template, [
            'link_context' => [
                TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
            ],
            'title' => 'Override title',
        ]);

        $this->assertSame('Override title', $task->title);
        $this->assertSame('Template body', $task->description);
        $this->assertSame(Task::SOURCE_MODULE, $task->source);
        $this->assertSame($template->getKey(), $task->task_template_id);
        $this->assertSame($template->key, $task->task_template_key);
        $this->assertSame(Task::RESPONSIBLE_PARTY_CONTACT, $task->responsible_party);
        $this->assertSame($contact->getMorphClass(), $task->responsible_type);
        $this->assertSame($contact->getKey(), $task->responsible_id);
        $this->assertSame('high', $task->priority);
        $this->assertNotNull($task->due_at);

        $this->assertDatabaseHas('task_links', [
            'task_id' => $task->getKey(),
            'linkable_type' => $contact->getMorphClass(),
            'linkable_id' => $contact->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);
    }

    public function test_template_creation_resolves_real_non_contact_current_subject(): void
    {
        $appointment = Appointment::factory()->create([
            'title' => 'Annual vaccination appointment',
        ]);

        $template = TaskTemplate::factory()->currentSubject()->create([
            'key' => 'test.review_appointment',
            'title' => 'Review appointment',
        ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template, [
            'link_context' => [
                TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $appointment,
            ],
        ]);

        $this->assertDatabaseHas('task_links', [
            'task_id' => $task->getKey(),
            'linkable_type' => $appointment->getMorphClass(),
            'linkable_id' => $appointment->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);
    }

    public function test_template_creation_fails_clearly_when_required_link_context_is_missing(): void
    {
        $template = TaskTemplate::factory()->currentContactSubject()->create([
            'key' => 'test.requires_contact',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires link context [current_contact]');

        app(CreateTaskFromTemplateAction::class)->handle($template);
    }

    public function test_explicit_and_default_links_merge_deduplicate_and_allow_same_record_under_different_roles(): void
    {
        $contact = Contact::factory()->create();

        $template = TaskTemplate::factory()->currentContactSubject()->create([
            'key' => 'test.deduplicated_links',
        ]);

        $task = app(CreateTaskFromTemplateAction::class)->handle($template, [
            'links' => [
                [
                    'linkable' => $contact,
                    'role' => TaskLink::ROLE_SUBJECT,
                ],
                [
                    'linkable' => $contact,
                    'role' => TaskLink::ROLE_CONTEXT,
                ],
            ],
            'link_context' => [
                TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
            ],
        ]);

        $this->assertSame(2, $task->links()->count());
        $this->assertSame(1, $task->subjectLinks()->count());
        $this->assertSame(1, $task->contextLinks()->count());
    }
}
