<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Jobs\CreateTaskForContactResultChunkJob;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactResultTaskActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('modules.enabled', ['tasks']);
    }

    public function test_result_action_freezes_visible_contacts_and_queues_template_backed_tasks(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $contacts = Contact::factory()->count(2)->create();
        $template = TaskTemplate::factory()->create(['is_active' => true]);

        $this->actingAs($actor)->post(route('crm.tasks.contact-results.store'), [
            'contact_result' => ['search' => '', 'criteria' => []],
            'task_template_id' => $template->getKey(),
        ])->assertRedirect(route('crm.contacts.index'));

        Queue::assertPushed(CreateTaskForContactResultChunkJob::class, function (CreateTaskForContactResultChunkJob $job) use ($contacts, $template): bool {
            return $job->contactIds === $contacts->modelKeys()
                && $job->taskTemplateId === (int) $template->getKey()
                && $job->taskTemplateKey === $template->key;
        });
    }

    public function test_result_job_creates_one_idempotent_contact_linked_task_per_contact(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();
        $template = TaskTemplate::factory()->create(['is_active' => true]);
        $job = new CreateTaskForContactResultChunkJob(
            contactIds: [(int) $contact->getKey()],
            taskTemplateId: (int) $template->getKey(),
            taskTemplateKey: $template->key,
            actorUserId: (int) $actor->getKey(),
            operationId: 'test-result-task-operation',
        );

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(1, Task::query()->count());
        $task = Task::query()->sole();
        $this->assertSame($template->key, $task->task_template_key);
        $this->assertDatabaseHas('task_links', [
            'task_id' => $task->getKey(),
            'linkable_type' => $contact->getMorphClass(),
            'linkable_id' => $contact->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);
    }
}