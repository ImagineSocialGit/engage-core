<?php

namespace Tests\Feature\Events;

use App\Modules\Events\Contracts\EventReadinessContributor;
use App\Modules\Events\Data\EventReadinessFinding;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventAnnouncementGate;
use App\Modules\Events\Services\EventDuplicateDetector;
use App\Modules\Events\Services\EventPromotionGate;
use App\Modules\Events\Services\EventReadinessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventReadinessAndPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_registers_core_readiness_without_closing_the_registry(): void
    {
        $this->app->register(EventsModuleServiceProvider::class);

        $registry = app(EventReadinessRegistry::class);

        $this->assertContains(
            CoreEventReadinessContributor::CAPABILITY,
            $registry->capabilities(),
        );
    }

    public function test_core_readiness_accepts_complete_physical_and_hybrid_events(): void
    {
        $registry = $this->coreReadinessRegistry();

        $physical = Event::factory()->create();
        $hybrid = Event::factory()->create([
            'attendance_mode' => 'hybrid',
        ]);

        $this->assertTrue($registry->evaluate($physical, 'core')->ready());
        $this->assertTrue($registry->evaluate($hybrid, 'core')->ready());
    }

    public function test_core_readiness_reports_required_physical_fields_and_region_rules(): void
    {
        $event = Event::factory()->make([
            'title' => ' ',
            'starts_at' => null,
            'timezone' => 'Not/A_Timezone',
            'venue_name' => null,
            'city' => null,
            'region' => null,
            'country' => 'US',
        ]);

        $result = $this->coreReadinessRegistry()->evaluate($event, 'core');

        $this->assertFalse($result->ready());
        $this->assertTrue($result->has('title_missing'));
        $this->assertTrue($result->has('starts_at_missing'));
        $this->assertTrue($result->has('timezone_invalid'));
        $this->assertTrue($result->has('venue_name_missing'));
        $this->assertTrue($result->has('city_missing'));
        $this->assertTrue($result->has('region_missing'));

        $international = Event::factory()->create([
            'title' => 'International Event',
            'starts_at' => now()->addWeek()->startOfMinute(),
            'timezone' => 'Europe/London',
            'venue_name' => 'Royal Hall',
            'city' => 'London',
            'country' => 'GB',
            'region' => null,
        ]);

        $this->assertTrue(
            $this->coreReadinessRegistry()->evaluate($international, 'core')->ready(),
        );
    }

    public function test_virtual_event_requires_a_structured_livestream_reference(): void
    {
        $event = Event::factory()->virtual()->create();
        $registry = $this->coreReadinessRegistry();

        $this->assertTrue(
            $registry->evaluate($event, 'core')->has('livestream_reference_missing'),
        );

        EventExternalReference::factory()->forEvent($event)->create([
            'provider_key' => 'youtube',
            'reference_type' => 'livestream',
            'external_id' => null,
            'url' => 'https://youtube.example.test/live/event-1',
        ]);

        $this->assertTrue($registry->evaluate($event, 'core')->ready());
    }

    public function test_announcement_gate_blocks_unknown_and_future_timing_then_opens_at_embargo(): void
    {
        $gate = new EventAnnouncementGate();
        $at = now()->startOfSecond();

        $unknown = Event::factory()->create([
            'announcement_at' => null,
        ]);
        $future = Event::factory()->create([
            'announcement_at' => $at->copy()->addMinute(),
        ]);
        $reached = Event::factory()->create([
            'announcement_at' => $at,
        ]);

        $this->assertTrue(
            $gate->decision($unknown, $at)->blockedBy(EventAnnouncementGate::BLOCKER_MISSING),
        );
        $this->assertTrue(
            $gate->decision($future, $at)->blockedBy(EventAnnouncementGate::BLOCKER_EMBARGO_ACTIVE),
        );
        $this->assertTrue($gate->allows($reached, $at));
    }

    public function test_upcoming_status_does_not_bypass_readiness_or_announcement_gates(): void
    {
        $gate = $this->promotionGate();
        $at = now()->startOfSecond();

        $event = Event::factory()->upcoming()->create([
            'venue_name' => null,
            'announcement_at' => $at->copy()->addHour(),
        ]);

        $decision = $gate->decision($event, $at);

        $this->assertFalse($decision->allowed());
        $this->assertTrue($decision->blockedBy('core_readiness.venue_name_missing'));
        $this->assertTrue($decision->blockedBy(EventAnnouncementGate::BLOCKER_EMBARGO_ACTIVE));

        $event->update([
            'venue_name' => 'Civic Hall',
            'announcement_at' => $at,
        ]);

        $this->assertTrue($gate->allows($event->refresh(), $at));
    }

    public function test_promotion_gate_blocks_non_upcoming_lifecycle_and_extensible_promotion_readiness(): void
    {
        $contributor = new class implements EventReadinessContributor
        {
            public function capability(): string
            {
                return EventPromotionGate::PROMOTION_READINESS_CAPABILITY;
            }

            public function findings(Event $event): iterable
            {
                if ($event->description === null) {
                    yield new EventReadinessFinding(
                        code: 'description_required_by_test_contributor',
                        message: 'Test promotion contribution requires a description.',
                        field: 'description',
                    );
                }
            }
        };

        $registry = new EventReadinessRegistry([
            new CoreEventReadinessContributor(),
            $contributor,
        ]);
        $gate = new EventPromotionGate(
            readiness: $registry,
            announcement: new EventAnnouncementGate(),
        );
        $at = now()->startOfSecond();

        $event = Event::factory()->create([
            'status' => EventStatus::Draft->value,
            'description' => null,
            'announcement_at' => $at,
        ]);

        $decision = $gate->decision($event, $at);

        $this->assertTrue($decision->blockedBy(EventPromotionGate::BLOCKER_LIFECYCLE));
        $this->assertTrue($decision->blockedBy(
            'promotion_readiness.description_required_by_test_contributor',
        ));

        $event->update([
            'status' => EventStatus::Upcoming->value,
            'description' => 'Ready for promotion.',
        ]);

        $this->assertTrue($gate->allows($event->refresh(), $at));
    }

    public function test_duplicate_detector_compares_normalized_local_event_identity_and_ignores_deleted_rows(): void
    {
        $detector = new EventDuplicateDetector();

        $existing = Event::factory()->create([
            'title' => 'Summer   Showcase',
            'venue_name' => 'Civic Hall',
            'city' => 'Chicago',
            'timezone' => 'America/Chicago',
            'starts_at' => '2026-10-10 01:00:00',
        ]);

        $sameLocalTimeDifferentZone = Event::factory()->create([
            'title' => 'summer showcase',
            'venue_name' => ' civic hall ',
            'city' => 'CHICAGO',
            'timezone' => 'America/New_York',
            'starts_at' => '2026-10-10 00:00:00',
        ]);

        $differentLocalTime = Event::factory()->create([
            'title' => 'Summer Showcase',
            'venue_name' => 'Civic Hall',
            'city' => 'Chicago',
            'timezone' => 'America/Chicago',
            'starts_at' => '2026-10-10 02:00:00',
        ]);

        $deletedMatch = Event::factory()->create([
            'title' => 'Summer Showcase',
            'venue_name' => 'Civic Hall',
            'city' => 'Chicago',
            'timezone' => 'America/Chicago',
            'starts_at' => '2026-10-10 01:00:00',
        ]);
        $deletedMatch->delete();

        $matches = $detector->similar($existing);

        $this->assertTrue($matches->contains($sameLocalTimeDifferentZone));
        $this->assertFalse($matches->contains($differentLocalTime));
        $this->assertFalse($matches->contains($deletedMatch));
        $this->assertFalse($matches->contains($existing));
    }

    private function coreReadinessRegistry(): EventReadinessRegistry
    {
        return new EventReadinessRegistry([
            new CoreEventReadinessContributor(),
        ]);
    }

    private function promotionGate(): EventPromotionGate
    {
        return new EventPromotionGate(
            readiness: $this->coreReadinessRegistry(),
            announcement: new EventAnnouncementGate(),
        );
    }
}