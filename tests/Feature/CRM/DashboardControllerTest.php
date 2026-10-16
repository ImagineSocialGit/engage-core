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

        $this->assertDatabaseHas('dashboard_acknowledgements', [
            'user_id' => $user->id,
            'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
            'item_type' => 'upcoming_tasks_week',
            'item_key' => '2026-07-06',
        ]);

        Carbon::setTestNow();
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

        $this->assertDatabaseHas('dashboard_acknowledgements', [
            'user_id' => $user->id,
            'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
            'item_type' => DashboardAcknowledgement::TYPE_INBOUND_MESSAGE,
            'item_key' => (string) $message->id,
        ]);

        $this->assertTrue($task->fresh()->isOpen());
    }


    public function test_dashboard_acknowledgement_only_accepts_single_slash_same_origin_return_paths(): void
    {
        $user = User::factory()->create();
        $fallback = route('crm.index');

        $unsafeReturnTos = [
            '//attacker.example/path',
            '///attacker.example/path',
            '\\\\attacker.example\\path',
            '/\\attacker.example/path',
            'https://attacker.example/path',
            rtrim(route('crm.index'), '/').'/contacts',
            '/%2F%2Fattacker.example/path',
            '/%252F%252Fattacker.example/path',
            '/%5Cattacker.example/path',
            "/contacts\r\nLocation: https://attacker.example",
            '/contacts%0D%0ALocation%3A%20https%3A%2F%2Fattacker.example',
        ];

        foreach ($unsafeReturnTos as $index => $returnTo) {
            $itemKey = 'unsafe-return-'.$index;

            $this->actingAs($user)
                ->post(route('crm.dashboard.acknowledgements.store'), [
                    'item_type' => 'safe_return_test',
                    'item_key' => $itemKey,
                    'return_to' => $returnTo,
                ])
                ->assertRedirect($fallback);

            $this->assertDatabaseHas('dashboard_acknowledgements', [
                'user_id' => $user->getKey(),
                'surface' => DashboardAcknowledgement::SURFACE_CRM_DASHBOARD,
                'item_type' => 'safe_return_test',
                'item_key' => $itemKey,
            ]);
        }

        $safeReturnTo = '/contacts/123?tab=activity#recent';

        $this->actingAs($user)
            ->post(route('crm.dashboard.acknowledgements.store'), [
                'item_type' => 'safe_return_test',
                'item_key' => 'safe-return',
                'return_to' => $safeReturnTo,
            ])
            ->assertRedirect(
                rtrim(route('crm.index'), '/').$safeReturnTo,
            );
    }

}