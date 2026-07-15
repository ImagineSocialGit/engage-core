<?php

namespace Tests\Feature\CRM;

use App\Models\DashboardAcknowledgement;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Tasks\Models\Task;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_a_daily_command_center_from_registered_dashboard_panels(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create([
            'name' => 'Tess Lead',
            'email' => 'tess@example.test',
        ]);

        Task::factory()->linkedTo($contact)->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call Tess about her reply',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subDay(),
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
        $response->assertSee('data-module-panel="tasks"', false);
        $response->assertSee('data-module-panel="inbound_messaging"', false);
        $response->assertSee('Today’s tasks');
        $response->assertSee('Print');
        $response->assertSee('Open task');
        $response->assertSee('Call Tess about her reply');
        $response->assertSee('Contacts needing attention');
        $response->assertSee('Review reply');
        $response->assertSee('Tess Lead replied');
        $response->assertSee('Overdue');
    }

    public function test_it_renders_a_caught_up_state_for_enabled_actionable_panels(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('You have a clear place to start.');
        $response->assertSee('No tasks need your attention today.');
        $response->assertSee('No contact replies need review.');
        $response->assertDontSee('No new webinar activity to review.');
        $response->assertDontSee('data-module-panel="webinars"', false);
    }

    public function test_configured_dashboard_slots_control_context_panels_when_modules_are_available(): void
    {
        Config::set('modules.dashboard.slots.context.panels', []);

        $user = User::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Context Lead']);
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'registered_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertDontSee('Webinar activity');
        $response->assertDontSee('data-module-panel="webinars"', false);
    }

    public function test_context_panels_appear_when_configured_and_they_have_useful_activity(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Webinar Lead']);
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'title' => 'Buyer Webinar',
            'starts_at' => now()->addDay(),
        ]);

        WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'registered_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('data-module-panel="webinars"', false);
        $response->assertSee('Webinar activity');
        $response->assertSee('Webinar Lead registered');
        $response->assertSee('Buyer Webinar');
    }

    public function test_it_keeps_future_dated_tasks_out_of_todays_task_list(): void
    {
        Carbon::setTestNow('2026-07-06 10:00:00');

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
            'Today’s tasks',
            'Overdue follow-up',
            'Undated follow-up',
            'Upcoming this week',
        ]);
        $response->assertSee('1 task due after today.');
        $response->assertSee('Hide for today');
        $response->assertDontSee('Due tomorrow');

        Carbon::setTestNow();
    }

    public function test_hide_for_today_acknowledges_the_upcoming_week_summary(): void
    {
        Carbon::setTestNow('2026-07-06 10:00:00');

        $user = User::factory()->create();

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Future follow-up summary item',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->addDay(),
        ]);

        $this
            ->actingAs($user)
            ->post(route('crm.dashboard.acknowledgements.store'), [
                'item_type' => 'upcoming_tasks_week',
                'item_key' => '2026-07-06',
            ])
            ->assertRedirect(route('crm.index'));

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertDontSee('Upcoming this week');
        $response->assertDontSee('Future follow-up summary item');

        $this->assertDatabaseHas('dashboard_acknowledgements', [
            'user_id' => $user->id,
            'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
            'item_type' => 'upcoming_tasks_week',
            'item_key' => '2026-07-06',
        ]);

        Carbon::setTestNow();
    }

    public function test_standalone_dashboard_task_links_to_dedicated_task_show_surface(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->create([
            'title' => 'Standalone dashboard task',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subHour(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('Standalone dashboard task');
        $response->assertSee(route('crm.tasks.show', $task), false);
        $response->assertSee('Open task');
    }
    public function test_it_renders_a_printable_task_list_without_future_dated_tasks(): void
    {
        $user = User::factory()->create();

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Print this task',
            'status' => Task::STATUS_OPEN,
            'due_at' => now(),
        ]);

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Do not print tomorrow',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->addDay(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.tasks.today.print'));

        $response->assertOk();
        $response->assertSee('Today’s Task List');
        $response->assertSee('Print this task');
        $response->assertDontSee('Do not print tomorrow');
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

        $task = Task::factory()->linkedTo($contact)->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Still open after acknowledgement',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subDay(),
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

    public function test_it_renders_muted_module_wayfinding_hooks_separately_from_urgency(): void
    {
        $user = User::factory()->create();

        Task::query()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Urgent follow-up',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subDay(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('data-module-panel="tasks"', false);
        $response->assertSee('bg-amber-50', false);
        $response->assertSee('Overdue');
    }

    public function test_disabled_modules_do_not_contribute_dashboard_panels_even_when_configured(): void
    {
        Config::set('modules.enabled', [
            'tasks',
            'messaging',
            'internal_notifications',
        ]);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('data-module-panel="tasks"', false);
        $response->assertSee('No tasks need your attention today.');
        $response->assertDontSee('data-module-panel="inbound_messaging"', false);
        $response->assertDontSee('No contact replies need review.');
        $response->assertDontSee('data-module-panel="webinars"', false);
        $response->assertDontSee('Webinar activity');
    }

    public function test_client_preset_can_disable_context_dashboard_panels(): void
    {
        Config::set('client.preset', 'crm_basic');

        $user = User::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Preset Webinar Lead']);
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'title' => 'Preset Hidden Webinar',
            'starts_at' => now()->addDay(),
        ]);

        WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'registered_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSee('data-module-panel="tasks"', false);
        $response->assertSee('data-module-panel="inbound_messaging"', false);
        $response->assertDontSee('data-module-panel="webinars"', false);
        $response->assertDontSee('Preset Hidden Webinar');
    }

    public function test_client_preset_can_prioritize_one_actionable_panel_over_another(): void
    {
        Config::set('client.preset', 'messaging_first');

        $user = User::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Priority Lead']);

        Task::factory()->linkedTo($contact)->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Overdue but lower preset priority',
            'status' => Task::STATUS_OPEN,
            'due_at' => now()->subDay(),
        ]);

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->id,
            'client_key' => config('client.key'),
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-dashboard-priority-test',
            'provider_message_id' => 'message-dashboard-priority-test',
            'from_type' => 'phone',
            'from_value' => '+15555550123',
            'to_type' => 'phone',
            'to_value' => '+15555550999',
            'body' => 'Please prioritize replies for this preset.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.index'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'Contacts needing attention',
            'Today’s tasks',
        ]);
        $response->assertSee('Priority Lead replied');
        $response->assertSee('Overdue but lower preset priority');
    }

}
