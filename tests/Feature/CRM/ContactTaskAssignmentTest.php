<?php

namespace Tests\Feature\CRM;

use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Messaging\Payloads\Internal\InternalSmsNotificationPayload;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberNotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_contact_task_notification_is_scheduled_when_notify_assignee_is_selected(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create([
            'email' => 'processor@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Review borrower docs',
                'description' => 'Check uploaded bank statements.',
                'notify_assignee' => '1',
            ]);

        $response->assertRedirect();

        $task = Task::query()
            ->where('title', 'Review borrower docs')
            ->firstOrFail();

        $scheduledMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame(TeamMember::class, $scheduledMessage->recipient_type);
        $this->assertSame($teamMember->id, $scheduledMessage->recipient_id);
        $this->assertSame(Task::class, $scheduledMessage->context_type);
        $this->assertSame($task->id, $scheduledMessage->context_id);
        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('internal', $scheduledMessage->purpose);
        $this->assertSame('crm_tasks', $scheduledMessage->scope);
        $this->assertSame('task_assigned', $scheduledMessage->message_type);
        $this->assertSame(InternalEmailNotificationPayload::class, $scheduledMessage->payload_class);
        $this->assertSame('pending', $scheduledMessage->status);

        $this->assertSame('processor@example.com', $scheduledMessage->payload['to']);
        $this->assertSame('New task assigned: Review borrower docs', $scheduledMessage->payload['subject']);
        $this->assertSame(
            TeamMemberNotificationPreference::TYPE_TASK_ASSIGNED,
            $scheduledMessage->payload['notification_type'],
        );

        Bus::assertDispatched(SendScheduledMessageJob::class);
    }

    public function test_contact_task_notification_is_not_scheduled_when_notify_assignee_is_not_selected(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create([
            'email' => 'processor@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Review borrower docs',
                'notify_assignee' => '0',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseCount('scheduled_messages', 0);

        Bus::assertNotDispatched(SendScheduledMessageJob::class);
    }

    public function test_contact_task_notification_respects_disabled_email_preference(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create([
            'email' => 'processor@example.com',
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->email()
            ->taskAssigned()
            ->disabled()
            ->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Review borrower docs',
                'notify_assignee' => '1',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseCount('scheduled_messages', 0);

        Bus::assertNotDispatched(SendScheduledMessageJob::class);
    }

    public function test_contact_task_notification_can_fall_back_to_sms_when_email_is_disabled_and_sms_is_enabled(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $teamMember = TeamMember::factory()->create([
            'email' => 'processor@example.com',
            'phone' => '+13213261815',
        ]);

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->email()
            ->taskAssigned()
            ->disabled()
            ->create();

        TeamMemberNotificationPreference::factory()
            ->for($teamMember)
            ->sms()
            ->taskAssigned()
            ->create([
                'enabled' => true,
            ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.tasks.store', $contact), [
                'assigned_to_id' => $teamMember->id,
                'title' => 'Review borrower docs',
                'notify_assignee' => '1',
            ]);

        $response->assertRedirect();

        $scheduledMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame(TeamMember::class, $scheduledMessage->recipient_type);
        $this->assertSame($teamMember->id, $scheduledMessage->recipient_id);
        $this->assertSame('sms', $scheduledMessage->channel);
        $this->assertSame('internal', $scheduledMessage->purpose);
        $this->assertSame('crm_tasks', $scheduledMessage->scope);
        $this->assertSame('task_assigned', $scheduledMessage->message_type);
        $this->assertSame(InternalSmsNotificationPayload::class, $scheduledMessage->payload_class);
        $this->assertSame('+13213261815', $scheduledMessage->payload['to']);
        $this->assertSame(
            TeamMemberNotificationPreference::TYPE_TASK_ASSIGNED,
            $scheduledMessage->payload['notification_type'],
        );

        Bus::assertDispatched(SendScheduledMessageJob::class);
    }

    public function test_contact_show_passes_current_team_member_to_view(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $currentTeamMember = TeamMember::factory()
            ->forUser($user)
            ->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact));

        $response
            ->assertOk()
            ->assertViewHas('currentTeamMember', function (?TeamMember $teamMember) use ($currentTeamMember): bool {
                return $teamMember?->is($currentTeamMember) === true;
            });
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