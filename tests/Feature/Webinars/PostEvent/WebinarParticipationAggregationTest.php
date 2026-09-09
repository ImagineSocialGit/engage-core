<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarAttendanceAction;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WebinarParticipationAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_provider_segments_are_aggregated_for_one_registration(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $registration] = $this->registration();

        $firstJoin = CarbonImmutable::parse('2026-09-07T23:51:49+00:00');
        $firstLeave = CarbonImmutable::parse('2026-09-07T23:52:44+00:00');
        $secondJoin = CarbonImmutable::parse('2026-09-07T23:52:44+00:00');
        $secondLeave = CarbonImmutable::parse('2026-09-08T01:29:45+00:00');

        app(RecordWebinarAttendanceAction::class)->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect([
                $this->attendanceRecord(
                    duration: 55,
                    joinTime: $firstJoin,
                    leaveTime: $firstLeave,
                ),
                $this->attendanceRecord(
                    duration: 5821,
                    joinTime: $secondJoin,
                    leaveTime: $secondLeave,
                ),
            ]),
            finalizeMissed: false,
        );

        $registration = $registration->fresh();
        $attendance = data_get($registration->meta, 'attendance');

        $this->assertSame('attended', $registration->status);
        $this->assertSame(
            $firstJoin->toIso8601String(),
            $registration->attended_at?->toIso8601String(),
        );
        $this->assertSame('zoom', $attendance['provider']);
        $this->assertSame('attended', $attendance['status']);
        $this->assertSame(5876, $attendance['duration']);
        $this->assertSame(
            $firstJoin->toIso8601String(),
            $attendance['join_time'],
        );
        $this->assertSame(
            $secondLeave->toIso8601String(),
            $attendance['leave_time'],
        );
        $this->assertSame('email', $attendance['matched_by']);
    }

    public function test_existing_attended_registration_is_refreshed_without_emitting_a_second_attended_event(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        $firstJoin = CarbonImmutable::parse('2026-09-07T23:54:41+00:00');
        $firstLeave = CarbonImmutable::parse('2026-09-07T23:55:36+00:00');
        $secondJoin = CarbonImmutable::parse('2026-09-07T23:55:36+00:00');
        $secondLeave = CarbonImmutable::parse('2026-09-08T01:29:34+00:00');

        [$webinar, $registration] = $this->registration([
            'status' => 'attended',
            'attended_at' => $firstJoin,
            'meta' => [
                'attendance' => [
                    'provider' => 'zoom',
                    'status' => 'attended',
                    'duration' => 55,
                    'join_time' => $firstJoin->toIso8601String(),
                    'leave_time' => $firstLeave->toIso8601String(),
                    'recorded_at' => CarbonImmutable::parse(
                        '2026-09-08T01:30:17+00:00',
                    )->toIso8601String(),
                    'matched_by' => 'email',
                ],
            ],
        ]);

        $outboxCountBefore = AutomationEventOutboxEvent::query()->count();

        app(RecordWebinarAttendanceAction::class)->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect([
                $this->attendanceRecord(
                    duration: 55,
                    joinTime: $firstJoin,
                    leaveTime: $firstLeave,
                ),
                $this->attendanceRecord(
                    duration: 5638,
                    joinTime: $secondJoin,
                    leaveTime: $secondLeave,
                ),
            ]),
            finalizeMissed: false,
        );

        $registration = $registration->fresh();
        $attendance = data_get($registration->meta, 'attendance');

        $this->assertSame(5693, $attendance['duration']);
        $this->assertSame(
            $firstJoin->toIso8601String(),
            $registration->attended_at?->toIso8601String(),
        );
        $this->assertSame(
            $secondLeave->toIso8601String(),
            $attendance['leave_time'],
        );
        $this->assertSame(
            $outboxCountBefore,
            AutomationEventOutboxEvent::query()->count(),
        );
    }

    public function test_overlapping_provider_segments_are_not_double_counted(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $registration] = $this->registration();

        $start = CarbonImmutable::parse('2026-09-07T23:50:00+00:00');

        app(RecordWebinarAttendanceAction::class)->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect([
                $this->attendanceRecord(
                    duration: 60,
                    joinTime: $start,
                    leaveTime: $start->addSeconds(60),
                ),
                $this->attendanceRecord(
                    duration: 60,
                    joinTime: $start->addSeconds(30),
                    leaveTime: $start->addSeconds(90),
                ),
            ]),
            finalizeMissed: false,
        );

        $attendance = data_get(
            $registration->fresh()->meta,
            'attendance',
        );

        $this->assertSame(90, $attendance['duration']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{Webinar, WebinarRegistration}
     */
    private function registration(array $overrides = []): array
    {
        $webinar = Webinar::factory()->create([
            'starts_at' => CarbonImmutable::parse(
                '2026-09-08T00:00:00+00:00',
            ),
            'ends_at' => CarbonImmutable::parse(
                '2026-09-08T01:45:00+00:00',
            ),
        ]);

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create(array_replace([
                'status' => 'registered',
                'attended_at' => null,
                'meta' => [],
            ], $overrides));

        return [$webinar, $registration];
    }

    private function attendanceRecord(
        int $duration,
        CarbonImmutable $joinTime,
        CarbonImmutable $leaveTime,
    ): WebinarAttendanceRecord {
        return new WebinarAttendanceRecord(
            registrantId: null,
            email: 'person@example.test',
            status: 'attended',
            duration: $duration,
            joinTime: $joinTime,
            leaveTime: $leaveTime,
            raw: [],
        );
    }
}