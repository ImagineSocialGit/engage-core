<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageOperationalEvent;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboundMessageWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_settings_workspace_filters_module_and_status_without_materializing_messages(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $webinar = ScheduledMessage::factory()->forRecipient($contact)->create([
            'send_at' => now()->addHour(),
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 987654,
        ]);
        $broadcast = ScheduledMessage::factory()->forRecipient($contact)->create([
            'send_at' => now()->addHours(2),
            'context_type' => 'App\\Modules\\Broadcasts\\Models\\Broadcast',
            'context_id' => 987654,
        ]);

        $this->assertContains(
            'crm.messaging.outbound.index',
            collect(app(ModuleManager::class)->settingsItems())->pluck('route')->all(),
        );

        $response = $this->actingAs($user)->get(route('crm.messaging.outbound.index', [
            'module' => 'webinars',
            'period' => 'upcoming',
            'message_status' => 'pending',
        ]));

        $response->assertOk()->assertViewHas('messages', function ($page) use ($webinar, $broadcast): bool {
            $ids = $page->getCollection()->modelKeys();

            return in_array($webinar->getKey(), $ids, true)
                && ! in_array($broadcast->getKey(), $ids, true);
        });
    }

    public function test_member_sees_only_assigned_contact_messages_and_cannot_control_hidden_rows(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $user = User::factory()->create();
        $other = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => true,
        ]);
        $visibleContact = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $hiddenContact = Contact::factory()->create(['assigned_user_id' => $other->getKey()]);
        $visible = ScheduledMessage::factory()->forRecipient($visibleContact)->create([
            'send_at' => now()->addHour(),
        ]);
        $hidden = ScheduledMessage::factory()->forRecipient($hiddenContact)->create([
            'send_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->get(route('crm.messaging.outbound.index'))
            ->assertOk()
            ->assertViewHas('messages', function ($page) use ($visible, $hidden): bool {
                $ids = $page->getCollection()->modelKeys();

                return in_array($visible->getKey(), $ids, true)
                    && ! in_array($hidden->getKey(), $ids, true);
            });

        $this->post(route('crm.messaging.outbound.control', $hidden), ['action' => 'cancel'])
            ->assertNotFound();
        $this->post(route('crm.messaging.outbound.control', $visible), ['action' => 'hold'])
            ->assertRedirect();

        $this->assertSame(ScheduledMessage::OPERATIONAL_HELD, $visible->fresh()->operational_state);
        $this->assertSame(1, ScheduledMessageOperationalEvent::query()
            ->where('scheduled_message_id', $visible->getKey())->count());
    }

    public function test_time_change_uses_the_client_timezone_and_records_a_manual_override(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        config()->set('client.timezone', 'America/Chicago');
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $message = ScheduledMessage::factory()->forRecipient($contact)->create([
            'send_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->post(route('crm.messaging.outbound.control', $message), [
            'action' => 'reschedule',
            'send_at' => '2026-09-18T09:00',
        ])->assertRedirect();

        $this->assertTrue($message->fresh()->send_at->equalTo(Carbon::parse('2026-09-18 14:00:00 UTC')));
        $this->assertNotNull($message->fresh()->manual_schedule_override_at);
        $this->assertSame('reschedule', $message->operationalEvents()->first()->action);
    }
}