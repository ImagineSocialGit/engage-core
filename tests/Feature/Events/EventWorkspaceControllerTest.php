<?php

namespace Tests\Feature\Events;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventWorkspaceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['events']);
        $this->app->register(EventsModuleServiceProvider::class, force: true);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_authenticated_operator_can_open_event_catalog_create_and_show_surfaces(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.events.index'))
            ->assertOk()
            ->assertViewHas('events');

        $this->actingAs($user)
            ->get(route('crm.events.create'))
            ->assertOk()
            ->assertViewHas('eventTypes');

        $this->actingAs($user)
            ->get(route('crm.events.show', $event))
            ->assertOk()
            ->assertViewHas('coreReadiness');
    }

    public function test_create_event_saves_a_draft_and_normalizes_local_schedule_to_utc(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('crm.events.store'), [
            'type_key' => 'concert',
            'title' => 'October Showcase',
            'attendance_mode' => 'physical',
            'starts_at_local' => '2026-10-15T19:00',
            'ends_at_local' => '2026-10-15T21:00',
            'timezone' => 'America/Chicago',
            'announcement_at_local' => '2026-10-01T09:00',
            'venue_name' => 'Civic Hall',
            'city' => 'Nashville',
            'region' => 'tn',
            'postal_code' => '37201',
            'country' => 'us',
        ]);

        $event = Event::query()->sole();

        $response->assertRedirect(route('crm.events.show', $event));
        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame('US', $event->country);
        $this->assertTrue($event->starts_at->equalTo(
            CarbonImmutable::parse('2026-10-16 00:00:00 UTC'),
        ));
        $this->assertTrue($event->ends_at?->equalTo(
            CarbonImmutable::parse('2026-10-16 02:00:00 UTC'),
        ) ?? false);
        $this->assertTrue($event->announcement_at?->equalTo(
            CarbonImmutable::parse('2026-10-01 14:00:00 UTC'),
        ) ?? false);
    }

    public function test_create_requires_explicit_confirmation_for_a_similar_event(): void
    {
        $user = User::factory()->create();
        $existing = Event::factory()->create([
            'title' => 'Autumn Showcase',
            'venue_name' => 'The Hall',
            'city' => 'Nashville',
            'starts_at' => '2026-10-11 00:00:00',
            'ends_at' => '2026-10-11 02:00:00',
            'timezone' => 'America/Chicago',
        ]);

        $payload = [
            'type_key' => 'concert',
            'title' => 'Autumn Showcase',
            'attendance_mode' => 'physical',
            'starts_at_local' => '2026-10-10T19:00',
            'ends_at_local' => '2026-10-10T21:00',
            'timezone' => 'America/Chicago',
            'venue_name' => 'The Hall',
            'city' => 'Nashville',
            'region' => 'TN',
            'country' => 'US',
        ];

        $this->actingAs($user)
            ->from(route('crm.events.create'))
            ->post(route('crm.events.store'), $payload)
            ->assertRedirect(route('crm.events.create'))
            ->assertSessionHasErrors('confirm_duplicate');

        $this->assertSame(1, Event::query()->count());

        $response = $this->actingAs($user)->post(
            route('crm.events.store'),
            $payload + ['confirm_duplicate' => '1'],
        );

        $this->assertSame(2, Event::query()->count());
        $created = Event::query()
            ->where('id', '<>', $existing->getKey())
            ->sole();
        $response->assertRedirect(route('crm.events.show', $created));
    }

    public function test_draft_can_be_edited_without_changing_lifecycle_state(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->patch(route('crm.events.update', $event), [
            'type_key' => 'seminar',
            'title' => 'Updated Event',
            'attendance_mode' => 'physical',
            'starts_at_local' => '2026-11-05T18:30',
            'ends_at_local' => '2026-11-05T20:00',
            'timezone' => 'America/Chicago',
            'announcement_at_local' => '',
            'venue_name' => 'Updated Hall',
            'city' => 'Nashville',
            'region' => 'TN',
            'country' => 'US',
        ]);

        $response->assertRedirect(route('crm.events.show', $event));

        $event->refresh();
        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame('Updated Event', $event->title);
        $this->assertSame('seminar', $event->type_key);
        $this->assertSame('Updated Hall', $event->venue_name);
        $this->assertNull($event->announcement_at);
    }

    public function test_promotion_surfaces_readiness_errors_and_uses_the_lifecycle_action_when_ready(): void
    {
        $user = User::factory()->create();
        $incomplete = Event::factory()->create([
            'venue_name' => null,
        ]);

        $this->actingAs($user)
            ->from(route('crm.events.show', $incomplete))
            ->post(route('crm.events.promote', $incomplete))
            ->assertRedirect(route('crm.events.show', $incomplete))
            ->assertSessionHasErrors('promotion');

        $this->assertSame(EventStatus::Draft, $incomplete->fresh()->status);

        $ready = Event::factory()->create([
            'announcement_at' => null,
        ]);

        $this->actingAs($user)
            ->post(route('crm.events.promote', $ready))
            ->assertRedirect(route('crm.events.show', $ready));

        $this->assertSame(EventStatus::Upcoming, $ready->fresh()->status);
    }

    public function test_non_draft_event_cannot_open_or_submit_the_draft_editor(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->upcoming()->create();

        $this->actingAs($user)
            ->get(route('crm.events.edit', $event))
            ->assertStatus(409);

        $this->actingAs($user)
            ->patch(route('crm.events.update', $event), [
                'title' => 'Should not save',
                'attendance_mode' => 'physical',
                'starts_at_local' => '2026-11-05T18:30',
                'timezone' => 'America/Chicago',
            ])
            ->assertStatus(409);

        $this->assertNotSame('Should not save', $event->fresh()->title);
    }
}