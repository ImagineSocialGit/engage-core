<?php

namespace Tests\Feature\Events;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Modules\Events\Models\EventExternalReference;
use App\Modules\Events\Models\EventStakeholder;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Support\Modules\ModuleManager;
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
            ->assertViewHas('coreReadiness')
            ->assertViewHas('externalReferenceProviders')
            ->assertViewHas('stakeholderRoles')
            ->assertViewHas('attendances');
    }

    public function test_events_has_primary_navigation_when_enabled(): void
    {
        $navigation = app(ModuleManager::class)->navigationItems();

        $this->assertTrue(collect($navigation)->contains(
            fn (array $item): bool =>
                $item['module'] === 'events'
                && $item['route'] === 'crm.events.index',
        ));
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

    public function test_operator_can_postpone_reschedule_cancel_and_complete_through_authoritative_lifecycle_actions(): void
    {
        $user = User::factory()->create();
        $rescheduled = Event::factory()->upcoming()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-01 02:00:00 UTC'),
            'timezone' => 'America/Chicago',
        ]);

        $this->actingAs($user)
            ->post(route('crm.events.postpone', $rescheduled))
            ->assertRedirect(route('crm.events.show', $rescheduled));

        $this->assertSame(EventStatus::Postponed, $rescheduled->fresh()->status);

        $this->actingAs($user)
            ->post(route('crm.events.reschedule', $rescheduled), [
                'starts_at_local' => '2026-11-10T19:30',
                'ends_at_local' => '2026-11-10T21:00',
                'timezone' => 'America/Chicago',
            ])
            ->assertRedirect(route('crm.events.show', $rescheduled));

        $rescheduled->refresh();
        $this->assertSame(EventStatus::Upcoming, $rescheduled->status);
        $this->assertTrue($rescheduled->starts_at->equalTo(
            CarbonImmutable::parse('2026-11-11 01:30:00 UTC'),
        ));

        $this->actingAs($user)
            ->post(route('crm.events.complete', $rescheduled))
            ->assertRedirect(route('crm.events.show', $rescheduled));

        $this->assertSame(EventStatus::Completed, $rescheduled->fresh()->status);

        $cancelled = Event::factory()->upcoming()->create();

        $this->actingAs($user)
            ->post(route('crm.events.cancel', $cancelled))
            ->assertRedirect(route('crm.events.show', $cancelled));

        $this->assertSame(EventStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_operator_can_manage_external_references_and_primary_selection(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.events.external-references.store', $event), [
                'provider_key' => 'website',
                'reference_type' => 'event_page',
                'external_id' => 'show-123',
                'url' => 'https://example.test/events/show-123',
                'label' => 'Official event page',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $reference = EventExternalReference::query()->sole();
        $this->assertSame($event->getKey(), $reference->event_id);

        $this->actingAs($user)
            ->patch(route('crm.events.external-references.primary', $event), [
                'external_reference_id' => $reference->getKey(),
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $this->assertSame($reference->getKey(), $event->fresh()->primary_external_reference_id);

        $this->actingAs($user)
            ->patch(route('crm.events.external-references.update', [$event, $reference]), [
                'provider_key' => 'youtube',
                'reference_type' => 'recording',
                'external_id' => 'video-123',
                'url' => 'https://www.youtube.com/watch?v=video-123',
                'label' => 'Event recording',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $reference->refresh();
        $this->assertSame('youtube', $reference->provider_key);
        $this->assertSame('recording', $reference->reference_type);

        $this->actingAs($user)
            ->delete(route('crm.events.external-references.destroy', [$event, $reference]))
            ->assertRedirect(route('crm.events.show', $event));

        $this->assertSoftDeleted('event_external_references', [
            'id' => $reference->getKey(),
        ]);
        $this->assertNull($event->fresh()->primary_external_reference_id);
    }

    public function test_external_reference_authoring_rejects_unregistered_vocabulary(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)
            ->from(route('crm.events.show', $event))
            ->post(route('crm.events.external-references.store', $event), [
                'provider_key' => 'not_registered',
                'reference_type' => 'event_page',
                'external_id' => 'show-123',
            ])
            ->assertRedirect(route('crm.events.show', $event))
            ->assertSessionHasErrors('provider_key');

        $this->assertSame(0, EventExternalReference::query()->count());
    }

    public function test_operator_can_manage_occurrence_specific_stakeholder_snapshots(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.events.stakeholders.store', $event), [
                'role_key' => 'venue_contact',
                'name' => 'Venue Manager',
                'organization' => 'Civic Hall',
                'email' => 'venue@example.test',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $stakeholder = EventStakeholder::query()->sole();
        $this->assertSame($event->getKey(), $stakeholder->event_id);
        $this->assertSame('venue_contact', $stakeholder->role_key);

        $this->actingAs($user)
            ->patch(route('crm.events.stakeholders.update', [$event, $stakeholder]), [
                'role_key' => 'promoter',
                'name' => 'Updated Promoter',
                'organization' => 'Promotions LLC',
                'email' => 'promoter@example.test',
                'phone' => '6155550100',
                'notes' => 'Occurrence-specific snapshot.',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $stakeholder->refresh();
        $this->assertSame('promoter', $stakeholder->role_key);
        $this->assertSame('Updated Promoter', $stakeholder->name);

        $this->actingAs($user)
            ->delete(route('crm.events.stakeholders.destroy', [$event, $stakeholder]))
            ->assertRedirect(route('crm.events.show', $event));

        $this->assertSoftDeleted('event_stakeholders', [
            'id' => $stakeholder->getKey(),
        ]);
    }

    public function test_attendance_authoring_records_corrections_and_enforces_contact_visibility(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');
        $event = Event::factory()->upcoming()->create([
            'timezone' => 'America/Chicago',
        ]);
        $visible = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
            'name' => 'Visible Contact',
        ]);
        $hidden = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
            'name' => 'Hidden Contact',
        ]);

        $this->actingAs($member)
            ->post(route('crm.events.attendance.store', $event), [
                'contact_id' => $visible->getKey(),
                'status' => EventAttendanceStatus::Attended->value,
                'observed_at_local' => '2026-10-15T20:00',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $attendance = EventAttendance::query()->sole();
        $this->assertSame($visible->getKey(), $attendance->contact_id);
        $this->assertSame(EventAttendanceStatus::Attended, $attendance->status);
        $this->assertTrue($attendance->observed_at->equalTo(
            CarbonImmutable::parse('2026-10-16 01:00:00 UTC'),
        ));

        $this->actingAs($member)
            ->post(route('crm.events.attendance.store', $event), [
                'contact_id' => $visible->getKey(),
                'status' => EventAttendanceStatus::DidNotAttend->value,
                'observed_at_local' => '2026-10-15T20:30',
            ])
            ->assertRedirect(route('crm.events.show', $event));

        $this->assertSame(1, EventAttendance::query()->count());
        $this->assertSame(
            EventAttendanceStatus::DidNotAttend,
            $attendance->fresh()->status,
        );

        $this->actingAs($member)
            ->post(route('crm.events.attendance.store', $event), [
                'contact_id' => $hidden->getKey(),
                'status' => EventAttendanceStatus::Attended->value,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('event_attendances', [
            'event_id' => $event->getKey(),
            'contact_id' => $hidden->getKey(),
        ]);
    }

    public function test_event_show_exposes_only_attendance_for_contacts_visible_to_the_operator(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');
        $event = Event::factory()->upcoming()->create();
        $visible = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $hidden = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
        ]);

        EventAttendance::factory()->create([
            'event_id' => $event->getKey(),
            'contact_id' => $visible->getKey(),
        ]);
        EventAttendance::factory()->create([
            'event_id' => $event->getKey(),
            'contact_id' => $hidden->getKey(),
        ]);

        $this->actingAs($member)
            ->get(route('crm.events.show', $event))
            ->assertOk()
            ->assertViewHas('attendances', function ($attendances) use ($visible): bool {
                return $attendances->count() === 1
                    && $attendances->first()->contact_id === $visible->getKey();
            });
    }

    private function profile(User $user, string $role): void
    {
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => true,
            'capability_overrides' => null,
        ]);
    }
}