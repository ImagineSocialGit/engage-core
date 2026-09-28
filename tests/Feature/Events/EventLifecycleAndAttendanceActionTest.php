<?php

namespace Tests\Feature\Events;

use App\Modules\Core\Models\Contact;
use App\Modules\Events\Actions\CancelEventAction;
use App\Modules\Events\Actions\CompleteEventAction;
use App\Modules\Events\Actions\CreateEventAction;
use App\Modules\Events\Actions\PostponeEventAction;
use App\Modules\Events\Actions\PromoteEventAction;
use App\Modules\Events\Actions\RecordEventAttendanceAction;
use App\Modules\Events\Actions\RescheduleEventAction;
use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventLifecycleAndAttendanceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(EventsModuleServiceProvider::class);
    }

    public function test_create_event_action_owns_draft_creation_duplicate_confirmation_and_created_signal(): void
    {
        $startsAt = CarbonImmutable::parse('2026-10-10 01:00:00 UTC');

        Event::factory()->create([
            'title' => 'Autumn Showcase',
            'venue_name' => 'The Hall',
            'city' => 'Nashville',
            'starts_at' => $startsAt,
            'timezone' => 'America/Chicago',
        ]);

        $attributes = [
            'type_key' => 'concert',
            'title' => '  Autumn   Showcase ',
            'attendance_mode' => 'physical',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(2),
            'timezone' => 'America/Chicago',
            'announcement_at' => $startsAt->subWeek(),
            'venue_name' => ' the hall ',
            'city' => 'NASHVILLE',
            'region' => 'TN',
            'country' => 'US',
        ];

        try {
            app(CreateEventAction::class)->handle(
                attributes: $attributes,
                context: $this->context(),
            );
            $this->fail('Duplicate confirmation should have been required.');
        } catch (EventActionBlockedException $exception) {
            $this->assertSame('duplicate_confirmation_required', $exception->blocker);
            $this->assertNotEmpty($exception->context['event_ids'] ?? []);
        }

        $created = app(CreateEventAction::class)->handle(
            attributes: $attributes,
            context: $this->context(),
            confirmDuplicate: true,
        );

        $this->assertSame(EventStatus::Draft, $created->status);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::CREATED,
            'contact_id' => null,
            'subject_type' => $created->getMorphClass(),
            'subject_id' => (string) $created->getKey(),
        ]);
    }

    public function test_promote_requires_core_readiness_and_explicit_duplicate_confirmation(): void
    {
        $incomplete = Event::factory()->create([
            'title' => '',
        ]);

        try {
            app(PromoteEventAction::class)->handle(
                event: $incomplete,
                context: $this->context(),
            );
            $this->fail('Core readiness should have blocked promotion.');
        } catch (EventActionBlockedException $exception) {
            $this->assertSame('core_readiness_failed', $exception->blocker);
            $this->assertContains('title_missing', $exception->context['codes'] ?? []);
        }

        $this->assertSame(EventStatus::Draft, $incomplete->fresh()->status);

        $existing = Event::factory()->upcoming()->create();
        $draft = Event::factory()->create([
            'title' => $existing->title,
            'venue_name' => $existing->venue_name,
            'city' => $existing->city,
            'starts_at' => $existing->starts_at,
            'timezone' => $existing->timezone,
        ]);

        try {
            app(PromoteEventAction::class)->handle(
                event: $draft,
                context: $this->context(),
            );
            $this->fail('Duplicate confirmation should have been required.');
        } catch (EventActionBlockedException $exception) {
            $this->assertSame('duplicate_confirmation_required', $exception->blocker);
        }

        $promoted = app(PromoteEventAction::class)->handle(
            event: $draft,
            context: $this->context(),
            confirmDuplicate: true,
        );

        $this->assertSame(EventStatus::Upcoming, $promoted->status);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => EventAutomationSignalRecorder::UPCOMING,
            'subject_type' => $promoted->getMorphClass(),
            'subject_id' => (string) $promoted->getKey(),
        ]);
    }

    public function test_postpone_reschedule_cancel_and_complete_follow_the_approved_state_machine(): void
    {
        $event = Event::factory()->upcoming()->create();

        $postponed = app(PostponeEventAction::class)->handle(
            event: $event,
            context: $this->context('operator'),
        );

        $this->assertSame(EventStatus::Postponed, $postponed->status);

        app(PostponeEventAction::class)->handle(
            event: $postponed,
            context: $this->context('operator'),
        );

        $this->assertSame(
            1,
            $this->signalCount(EventAutomationSignalRecorder::POSTPONED, $event),
        );

        $newStartsAt = $event->starts_at->addWeek();
        $newEndsAt = $event->ends_at?->addWeek();

        $rescheduled = app(RescheduleEventAction::class)->handle(
            event: $postponed,
            startsAt: $newStartsAt,
            endsAt: $newEndsAt,
            timezone: 'America/Chicago',
            context: $this->context('operator'),
        );

        $this->assertSame(EventStatus::Upcoming, $rescheduled->status);
        $this->assertTrue($rescheduled->starts_at->equalTo($newStartsAt));
        $this->assertSame(
            1,
            $this->signalCount(EventAutomationSignalRecorder::RESCHEDULED, $event),
        );

        $cancelled = app(CancelEventAction::class)->handle(
            event: $rescheduled,
            context: $this->context('operator'),
        );

        $this->assertSame(EventStatus::Cancelled, $cancelled->status);
        $this->assertSame(
            1,
            $this->signalCount(EventAutomationSignalRecorder::CANCELLED, $event),
        );

        $completionTarget = Event::factory()->upcoming()->create();
        $completed = app(CompleteEventAction::class)->handle(
            event: $completionTarget,
            context: $this->context('operator'),
        );

        $this->assertSame(EventStatus::Completed, $completed->status);
        $this->assertSame(
            1,
            $this->signalCount(EventAutomationSignalRecorder::COMPLETED, $completionTarget),
        );
    }

    public function test_lifecycle_state_rolls_back_when_automation_outbox_write_fails(): void
    {
        $outbox = Mockery::mock(AutomationEventOutbox::class);
        $outbox->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Simulated Events outbox failure.'));
        app()->instance(AutomationEventOutbox::class, $outbox);

        $event = Event::factory()->upcoming()->create();

        try {
            app(PostponeEventAction::class)->handle(
                event: $event,
                context: $this->context(),
            );
            $this->fail('The lifecycle mutation should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Events outbox failure.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(EventStatus::Upcoming, $event->fresh()->status);
    }

    public function test_attendance_action_is_source_validated_latest_observation_wins_and_replays_are_idempotent(): void
    {
        $event = Event::factory()->upcoming()->create();
        $contact = Contact::factory()->create();
        $observedAt = CarbonImmutable::parse('2026-10-12 03:00:00 UTC');

        $attendance = app(RecordEventAttendanceAction::class)->handle(
            event: $event,
            contact: $contact,
            status: EventAttendanceStatus::Attended,
            observedAt: $observedAt,
            sourceKey: 'operator',
            sourceReference: 'check-in-42',
        );

        $this->assertSame(EventAttendanceStatus::Attended, $attendance->status);
        $this->assertSame(
            1,
            $this->attendanceSignalCount($attendance),
        );

        $replayed = app(RecordEventAttendanceAction::class)->handle(
            event: $event,
            contact: $contact,
            status: EventAttendanceStatus::Attended,
            observedAt: $observedAt,
            sourceKey: 'operator',
            sourceReference: 'check-in-42',
        );

        $this->assertSame($attendance->getKey(), $replayed->getKey());
        $this->assertSame(
            1,
            $this->attendanceSignalCount($attendance),
        );

        $stale = app(RecordEventAttendanceAction::class)->handle(
            event: $event,
            contact: $contact,
            status: EventAttendanceStatus::DidNotAttend,
            observedAt: $observedAt->subMinute(),
            sourceKey: 'operator',
            sourceReference: 'stale-correction',
        );

        $this->assertSame(EventAttendanceStatus::Attended, $stale->status);
        $this->assertSame(
            1,
            $this->attendanceSignalCount($attendance),
        );

        $corrected = app(RecordEventAttendanceAction::class)->handle(
            event: $event,
            contact: $contact,
            status: EventAttendanceStatus::DidNotAttend,
            observedAt: $observedAt->addMinute(),
            sourceKey: 'operator',
            sourceReference: 'correction-43',
        );

        $this->assertSame(EventAttendanceStatus::DidNotAttend, $corrected->status);
        $this->assertSame(
            2,
            $this->attendanceSignalCount($attendance),
        );

        try {
            app(RecordEventAttendanceAction::class)->handle(
                event: $event,
                contact: $contact,
                status: EventAttendanceStatus::Attended,
                observedAt: $observedAt->addMinutes(2),
                sourceKey: 'unknown_provider',
            );
            $this->fail('An unregistered attendance source should be rejected.');
        } catch (EventActionBlockedException $exception) {
            $this->assertSame('attendance_source_unregistered', $exception->blocker);
        }
    }

    private function context(string $source = 'test'): EventActionContext
    {
        return new EventActionContext(
            source: $source,
            occurredAt: CarbonImmutable::parse('2026-10-01 12:00:00 UTC'),
        );
    }

    private function signalCount(string $eventKey, Event $event): int
    {
        return DB::table('automation_event_outbox_events')
            ->where('event_key', $eventKey)
            ->where('subject_type', $event->getMorphClass())
            ->where('subject_id', (string) $event->getKey())
            ->count();
    }

    private function attendanceSignalCount(EventAttendance $attendance): int
    {
        return DB::table('automation_event_outbox_events')
            ->where('event_key', EventAutomationSignalRecorder::ATTENDANCE_RECORDED)
            ->where('subject_type', $attendance->getMorphClass())
            ->where('subject_id', (string) $attendance->getKey())
            ->count();
    }
}