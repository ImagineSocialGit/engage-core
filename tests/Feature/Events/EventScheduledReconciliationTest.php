<?php

namespace Tests\Feature\Events;

use App\Modules\Events\Actions\ReconcileDueEventsAction;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventScheduledReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(EventsModuleServiceProvider::class);
    }

    public function test_reconciliation_completes_ended_upcoming_events_before_recording_due_announcements(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 18:00:00 UTC');

        $ended = Event::factory()
            ->upcoming()
            ->create([
                'starts_at' => $now->subHours(3),
                'ends_at' => $now->subHour(),
                'announcement_at' => $now->subHours(4),
            ]);

        $announcementOnly = Event::factory()
            ->upcoming()
            ->create([
                'starts_at' => $now->addDay(),
                'ends_at' => $now->addDay()->addHours(2),
                'announcement_at' => $now->subMinutes(30),
            ]);

        $result = app(ReconcileDueEventsAction::class)->handle($now);

        $this->assertSame(1, $result['completed']);
        $this->assertSame(1, $result['announcements_recorded']);
        $this->assertSame(EventStatus::Completed, $ended->fresh()->status);
        $this->assertSame(EventStatus::Upcoming, $announcementOnly->fresh()->status);

        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::COMPLETED,
            'subject_type' => $ended->getMorphClass(),
            'subject_id' => (string) $ended->getKey(),
        ]);
        $this->assertDatabaseMissing('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::ANNOUNCEMENT_REACHED,
            'subject_type' => $ended->getMorphClass(),
            'subject_id' => (string) $ended->getKey(),
        ]);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::ANNOUNCEMENT_REACHED,
            'subject_type' => $announcementOnly->getMorphClass(),
            'subject_id' => (string) $announcementOnly->getKey(),
        ]);
    }

    public function test_reconciliation_does_not_infer_completion_without_an_elapsed_end_time(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 18:00:00 UTC');

        $missingEnd = Event::factory()
            ->upcoming()
            ->create([
                'starts_at' => $now->subHours(2),
                'ends_at' => null,
                'announcement_at' => null,
            ]);

        $futureEnd = Event::factory()
            ->upcoming()
            ->create([
                'starts_at' => $now->subHour(),
                'ends_at' => $now->addHour(),
                'announcement_at' => null,
            ]);

        $draft = Event::factory()->create([
            'starts_at' => $now->subHours(3),
            'ends_at' => $now->subHour(),
            'announcement_at' => null,
        ]);

        $result = app(ReconcileDueEventsAction::class)->handle($now);

        $this->assertSame(0, $result['completed']);
        $this->assertSame(EventStatus::Upcoming, $missingEnd->fresh()->status);
        $this->assertSame(EventStatus::Upcoming, $futureEnd->fresh()->status);
        $this->assertSame(EventStatus::Draft, $draft->fresh()->status);
        $this->assertDatabaseMissing('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::COMPLETED,
        ]);
    }

    public function test_announcement_reconciliation_is_durable_and_idempotent(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 18:00:00 UTC');
        $announcementAt = $now->subMinutes(20);

        $event = Event::factory()->create([
            'announcement_at' => $announcementAt,
            'ends_at' => $now->addDay(),
        ]);

        $first = app(ReconcileDueEventsAction::class)->handle($now);
        $second = app(ReconcileDueEventsAction::class)->handle($now->addMinute());

        $this->assertSame(1, $first['announcements_recorded']);
        $this->assertSame(0, $second['announcements_recorded']);

        $outboxEvents = AutomationEventOutboxEvent::query()
            ->where('event_key', EventAutomationSignalRecorder::ANNOUNCEMENT_REACHED)
            ->where('subject_type', $event->getMorphClass())
            ->where('subject_id', (string) $event->getKey())
            ->get();

        $this->assertCount(1, $outboxEvents);
        $this->assertSame(
            $announcementAt->toISOString(),
            $outboxEvents->first()->occurred_at?->toISOString(),
        );
        $this->assertSame(
            'events:event:'.$event->getKey().':announcement_reached',
            $outboxEvents->first()->idempotency_key,
        );
        $this->assertTrue(
            (bool) data_get($outboxEvents->first()->meta, 'automatic'),
        );
    }
}