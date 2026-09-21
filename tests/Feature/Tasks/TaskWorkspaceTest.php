<?php

namespace Tests\Feature\Tasks;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_includes_only_tasks_actually_linked_to_that_contact(): void
    {
        config()->set('modules.enabled', ['tasks']);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();

        Task::factory()->linkedTo($contact)->create([
            'title' => 'Linked to this contact',
        ]);

        Task::factory()->create([
            'title' => 'Standalone task should not leak',
        ]);

        Task::factory()->linkedTo($otherContact)->create([
            'title' => 'Different contact task',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this
            ->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->getKey());

        $response->assertOk();
        $response->assertSee('Linked to this contact');
        $response->assertDontSee('Standalone task should not leak');
        $response->assertDontSee('Different contact task');
    }

    public function test_http_store_can_create_standalone_manual_task(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('crm.tasks.store'), [
                'title' => 'Standalone task from workspace',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            ])
            ->assertRedirect();

        $task = Task::query()
            ->where('title', 'Standalone task from workspace')
            ->firstOrFail();

        $this->assertSame(Task::SOURCE_MANUAL, $task->source);
        $this->assertSame(0, $task->links()->count());
    }

    public function test_http_store_can_create_contact_linked_manual_task(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('crm.tasks.store'), [
                'title' => 'Contact-linked task from workspace',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'links' => [
                    [
                        'role' => TaskLink::ROLE_SUBJECT,
                        'linkable_type' => $contact->getMorphClass(),
                        'linkable_id' => $contact->getKey(),
                    ],
                ],
            ])
            ->assertRedirect();

        $task = Task::query()
            ->where('title', 'Contact-linked task from workspace')
            ->firstOrFail();

        $this->assertDatabaseHas('task_links', [
            'task_id' => $task->getKey(),
            'linkable_type' => $contact->getMorphClass(),
            'linkable_id' => $contact->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);
    }
}