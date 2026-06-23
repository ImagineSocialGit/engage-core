<?php

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_task_can_be_assigned_to_active_team_member(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create([
            'name' => 'Jane Processor',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Follow up with borrower',
                'description' => 'Confirm documents were received.',
                'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => $teamMember->id,
            'related_type' => Contact::class,
            'related_id' => $contact->id,
            'title' => 'Follow up with borrower',
            'description' => 'Confirm documents were received.',
            'status' => 'open',
            'completed_at' => null,
        ]);

        $task = Task::query()->firstOrFail();

        $this->assertTrue($task->assignedTo->is($teamMember));
        $this->assertTrue($task->related->is($contact));
    }

    public function test_contact_task_cannot_be_assigned_to_inactive_team_member(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->inactive()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Follow up with borrower',
            ]);

        $response
            ->assertRedirect(route('crm.contacts.show', $contact))
            ->assertSessionHasErrors('assigned_to_id');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_contact_task_can_be_completed(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create();

        $task = Task::factory()
            ->assignedTo($teamMember)
            ->relatedTo($contact)
            ->create([
                'status' => 'open',
                'completed_at' => null,
            ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.contacts.tasks.complete', [$contact, $task]));

        $response->assertRedirect();

        $task->refresh();
        $contact->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($contact->last_contacted_at);
        $this->assertNotNull($contact->last_activity_at);
    }

    public function test_contact_task_can_be_reopened(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create();

        $task = Task::factory()
            ->assignedTo($teamMember)
            ->relatedTo($contact)
            ->completed()
            ->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.contacts.tasks.reopen', [$contact, $task]));

        $response->assertRedirect();

        $task->refresh();

        $this->assertSame('open', $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_contact_task_actions_require_task_to_be_related_to_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create();

        $task = Task::factory()
            ->assignedTo($teamMember)
            ->relatedTo($otherContact)
            ->create([
                'status' => 'open',
                'completed_at' => null,
            ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.contacts.tasks.complete', [$contact, $task]));

        $response->assertNotFound();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'open',
            'completed_at' => null,
        ]);
    }
}