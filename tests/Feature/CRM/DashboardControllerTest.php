<?php

namespace Tests\Feature\CRM;

use App\Models\DashboardAcknowledgement;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_a_daily_command_center(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create([
            'name' => 'Tess Lead',
            'email' => 'tess@example.test',
        ]);

        Task::query()->create([
            'related_type' => $contact->getMorphClass(),
            'related_id' => $contact->id,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call Tess about her reply',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subHour(),
        ]);

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->id,
            'client_key' => config('client.key'),
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-dashboard-test',
            'provider_message_id' => 'message-dashboard-test',
            'from_type' => 'phone',
            'from_value' => '+15555550123',
            'to_type' => 'phone',
            'to_value' => '+15555550999',
            'body' => 'Can you call me today?',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('Today');
        $response->assertSee('You have a clear place to start.');
        $response->assertSee('Tasks for today');
        $response->assertSee('Print');
        $response->assertSee('View');
        $response->assertSee('Call Tess about her reply');
        $response->assertSee('Leads needing attention');
        $response->assertSee('Tess Lead replied');
        $response->assertSee('Webinar activity');
    }

    public function test_it_renders_a_caught_up_state_when_nothing_needs_attention(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('You have a clear place to start.');
        $response->assertSee('No tasks need your attention today.');
        $response->assertSee('No lead replies need review.');
        $response->assertSee('No new webinar activity to review.');
    }

    public function test_it_sorts_open_tasks_by_due_date_then_undated_tasks(): void
    {
        $user = User::factory()->create();

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Undated follow-up',
            'status' => Task::STATUS_OPEN,
            'due_at' => null,
        ]);

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Due tomorrow',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->addDay(),
        ]);

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Overdue follow-up',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subDay(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'Overdue follow-up',
            'Due tomorrow',
            'Undated follow-up',
        ]);
    }

    public function test_it_renders_a_printable_task_list(): void
    {
        $user = User::factory()->create();

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Print this task',
            'status' => Task::STATUS_OPEN,
            'due_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.tasks.today.print'));

        $response->assertOk();
        $response->assertSee('Today’s Task List');
        $response->assertSee('Print this task');
    }

    public function test_it_broadcasts_the_dashboard_task_list_to_active_team_members(): void
    {
        Queue::fake();

        Config::set('messaging.channel_availability.email.runtime_supported', true);
        Config::set('messaging.channel_availability.email.provider_enabled', true);
        Config::set('messaging.channel_availability.email.surfaces.internal_notifications', true);
        Config::set('messaging.channel_availability.email.purpose_scopes.*', true);

        $user = User::factory()->create();

        TeamMember::query()->create([
            'name' => 'Taylor Team',
            'email' => 'taylor@example.test',
            'is_active' => true,
        ]);

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Share this task',
            'status' => Task::STATUS_OPEN,
            'due_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.tasks.today.broadcast'));

        $response->assertRedirect(route('crm.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => (new TeamMember())->getMorphClass(),
            'channel' => 'email',
            'purpose' => 'internal',
            'scope' => 'tasks',
            'message_type' => 'dashboard_task_list',
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);
    }

    public function test_it_clears_seen_dashboard_items_without_hiding_open_tasks(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create([
            'name' => 'Clearable Lead',
            'email' => 'clearable@example.test',
        ]);

        $task = Task::query()->create([
            'related_type' => $contact->getMorphClass(),
            'related_id' => $contact->id,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Still open after acknowledgement',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subHour(),
        ]);

        $message = InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->id,
            'client_key' => config('client.key'),
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-dashboard-ack-test',
            'provider_message_id' => 'message-dashboard-ack-test',
            'from_type' => 'phone',
            'from_value' => '+15555550123',
            'to_type' => 'phone',
            'to_value' => '+15555550999',
            'body' => 'Please clear this from the dashboard.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->post(route('crm.dashboard.acknowledgements.store'), [
                'item_type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
                'item_key' => (string) $message->id,
            ])
            ->assertRedirect(route('crm.index'));

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('Still open after acknowledgement');
        $response->assertDontSee('Clearable Lead replied');

        $this->assertDatabaseHas('dashboard_acknowledgements', [
            'user_id' => $user->id,
            'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
            'item_type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
            'item_key' => (string) $message->id,
        ]);

        $this->assertTrue($task->fresh()->isOpen());
    }
}
