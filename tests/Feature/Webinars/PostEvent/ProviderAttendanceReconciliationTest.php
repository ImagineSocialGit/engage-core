<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderAttendanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_authoritative_empty_snapshot_keeps_registrations_unresolved(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $matchedRegistration, $unmatchedRegistration] = $this->webinarWithRegistrations();
        $provider = $this->providerReturning(
            ProviderAttendanceSnapshot::nonAuthoritative(
                records: [],
                reason: 'no_participant_records',
            ),
        );

        $result = app(RecordWebinarProviderAttendanceAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $this->assertFalse($result);
        $this->assertSame('registered', $matchedRegistration->fresh()->status);
        $this->assertSame('registered', $unmatchedRegistration->fresh()->status);

        $postEvent = data_get($webinar->fresh()->meta, 'normalized.post_event');

        $this->assertFalse($postEvent['attendance_ready']);
        $this->assertFalse($postEvent['attendance_snapshot_authoritative']);
        $this->assertSame('no_participant_records', $postEvent['attendance_snapshot_reason']);
        $this->assertSame(0, $postEvent['attendance_record_count']);
        $this->assertArrayNotHasKey('attendance_recorded_at', $postEvent);
    }

    public function test_non_authoritative_snapshot_applies_positive_evidence_without_marking_unmatched_registrations_missed(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $matchedRegistration, $unmatchedRegistration] = $this->webinarWithRegistrations();
        $provider = $this->providerReturning(
            ProviderAttendanceSnapshot::nonAuthoritative(
                records: [$this->attendanceRecord()],
                reason: 'invalid_provider_pagination_token',
            ),
        );

        $result = app(RecordWebinarProviderAttendanceAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $this->assertFalse($result);
        $this->assertSame('attended', $matchedRegistration->fresh()->status);
        $this->assertNotNull($matchedRegistration->fresh()->attended_at);
        $this->assertSame('registered', $unmatchedRegistration->fresh()->status);
        $this->assertSame(
            'invalid_provider_pagination_token',
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_snapshot_reason'),
        );
        $this->assertNull(
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_recorded_at'),
        );
    }

    public function test_explicit_authoritative_empty_snapshot_finalizes_registered_people_as_missed(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $firstRegistration, $secondRegistration] = $this->webinarWithRegistrations();
        $provider = $this->providerReturning(
            ProviderAttendanceSnapshot::authoritative([]),
        );

        $result = app(RecordWebinarProviderAttendanceAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $this->assertTrue($result);
        $this->assertSame('missed', $firstRegistration->fresh()->status);
        $this->assertSame('missed', $secondRegistration->fresh()->status);

        $postEvent = data_get($webinar->fresh()->meta, 'normalized.post_event');

        $this->assertTrue($postEvent['attendance_ready']);
        $this->assertTrue($postEvent['attendance_snapshot_authoritative']);
        $this->assertSame('authoritative_snapshot', $postEvent['attendance_finalization_reason']);
        $this->assertArrayHasKey('attendance_recorded_at', $postEvent);
        $this->assertArrayNotHasKey('attendance_snapshot_reason', $postEvent);
    }

    public function test_later_attended_evidence_takes_precedence_over_a_prior_missed_outcome(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $matchedRegistration, $unmatchedRegistration] = $this->webinarWithRegistrations();
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('key')
            ->twice()
            ->andReturn('zoom');
        $provider->shouldReceive('listAttendanceRecords')
            ->twice()
            ->andReturn(
                ProviderAttendanceSnapshot::authoritative([]),
                ProviderAttendanceSnapshot::authoritative([$this->attendanceRecord()]),
            );

        $action = app(RecordWebinarProviderAttendanceAction::class);

        $this->assertTrue($action->execute($provider, $webinar, 'webinar.ended'));
        $this->assertSame('missed', $matchedRegistration->fresh()->status);
        $this->assertSame('missed', $unmatchedRegistration->fresh()->status);

        $this->assertTrue($action->execute($provider, $webinar->fresh(), 'webinar.ended'));
        $this->assertSame('attended', $matchedRegistration->fresh()->status);
        $this->assertNotNull($matchedRegistration->fresh()->attended_at);
        $this->assertSame('missed', $unmatchedRegistration->fresh()->status);
    }

    public function test_provider_failure_records_an_actionable_unresolved_state_without_changing_registration_outcomes(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $firstRegistration, $secondRegistration] = $this->webinarWithRegistrations();
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('key')->once()->andReturn('zoom');
        $provider->shouldReceive('listAttendanceRecords')
            ->once()
            ->andThrow(new RuntimeException('Provider unavailable.'));

        try {
            app(RecordWebinarProviderAttendanceAction::class)->execute(
                provider: $provider,
                webinar: $webinar,
                event: 'webinar.ended',
            );

            $this->fail('The provider failure should remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider unavailable.', $exception->getMessage());
        }

        $this->assertSame('registered', $firstRegistration->fresh()->status);
        $this->assertSame('registered', $secondRegistration->fresh()->status);
        $this->assertFalse(
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_ready'),
        );
        $this->assertSame(
            'provider_request_failed',
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_snapshot_reason'),
        );
        $this->assertNull(
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_recorded_at'),
        );
    }

    public function test_crm_archived_webinar_list_surfaces_unresolved_attendance_reason(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'ends_at' => now()->subHour(),
            'meta' => [
                'normalized' => [
                    'post_event' => [
                        'attendance_checked_at' => now()->toIso8601String(),
                        'attendance_ready' => false,
                        'attendance_snapshot_reason' => 'no_participant_records',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(
            route('crm.webinar-series.index', ['archived' => 1]),
        );

        $response->assertOk();
        $response->assertSee($webinar->title);
        $response->assertSee('Attendance unresolved: No Participant Records');
    }

    /**
     * @return array{Webinar, WebinarRegistration, WebinarRegistration}
     */
    private function webinarWithRegistrations(): array
    {
        $webinar = Webinar::factory()->create([
            'ends_at' => now()->subHour(),
            'meta' => [],
        ]);

        $matchedContact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);
        $unmatchedContact = Contact::factory()->create([
            'email' => 'other@example.com',
        ]);

        $matchedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($matchedContact)
            ->create([
                'status' => 'registered',
                'attended_at' => null,
                'meta' => [
                    'provider' => [
                        'data' => [
                            'registrant_id' => 'registrant-1',
                        ],
                    ],
                ],
            ]);

        $unmatchedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($unmatchedContact)
            ->create([
                'status' => 'registered',
                'attended_at' => null,
                'meta' => [],
            ]);

        return [$webinar, $matchedRegistration, $unmatchedRegistration];
    }

    private function attendanceRecord(): WebinarAttendanceRecord
    {
        return new WebinarAttendanceRecord(
            registrantId: 'registrant-1',
            email: 'person@example.com',
            status: 'attended',
            duration: 3600,
            joinTime: now()->subMinutes(55),
            leaveTime: now()->subMinutes(5),
            raw: ['provider_record' => true],
        );
    }

    private function providerReturning(ProviderAttendanceSnapshot $snapshot): WebinarProvider
    {
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('key')->once()->andReturn('zoom');
        $provider->shouldReceive('listAttendanceRecords')
            ->once()
            ->andReturn($snapshot);

        return $provider;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
