<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Models\User;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\EnsureWebinarPostEventReviewAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;
use App\Modules\Webinars\Actions\PostEvent\ReviewWebinarPostEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use Tests\TestCase;

class WebinarPostEventReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_review_marks_completed_webinar_pending_and_surfaces_dashboard_action(): void
    {
        Config::set('webinars.post_event.review.required', true);

        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'title' => 'Homebuyer Game Plan',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        WebinarRegistration::factory()->create([
            'webinar_id' => $webinar->id,
            'status' => 'attended',
            'attended_at' => now()->subHour(),
        ]);

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('key')->zeroOrMoreTimes()->andReturn('zoom');
        });

        app(EnsureWebinarPostEventReviewAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $webinar->refresh();

        $this->assertSame(
            'pending',
            data_get($webinar->meta, 'normalized.post_event.review.status'),
        );

        $this->actingAs($user)
            ->get(route('crm.index'))
            ->assertOk()
            ->assertSee('Review webinar follow-ups')
            ->assertSee('Homebuyer Game Plan');
    }

    public function test_recording_completed_pipeline_reconciles_attendance_before_replay_and_follow_ups(): void
    {
        Config::set(
            'webinars.post_event',
            require base_path('config/webinars/post_event.php'),
        );

        $events = config('webinars.post_event.events', []);

        $actions = is_array($events)
            ? ($events['webinar.recording_completed'] ?? [])
            : [];

        $attendanceIndex = array_search(
            RecordWebinarProviderAttendanceAction::class,
            $actions,
            true,
        );
        $playbackIndex = array_search(
            ResolveWebinarPlaybackAction::class,
            $actions,
            true,
        );
        $dispatchIndex = array_search(
            DispatchPostWebinarFollowUpsAction::class,
            $actions,
            true,
        );

        $this->assertIsInt($attendanceIndex);
        $this->assertIsInt($playbackIndex);
        $this->assertIsInt($dispatchIndex);
        $this->assertLessThan($playbackIndex, $attendanceIndex);
        $this->assertLessThan($dispatchIndex, $playbackIndex);
    }

    public function test_operator_can_approve_previous_recording_from_same_series(): void
    {
        Config::set('webinars.post_event.review.required', true);

        $series = WebinarSeries::factory()->create();
        $current = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => [
                'normalized' => [
                    'post_event' => [
                        'attendance_recorded_at' => now()->subMinutes(50)->toIso8601String(),
                    ],
                ],
            ],
        ]);
        $previous = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->subWeeks(2)->subHour(),
            'ends_at' => now()->subWeeks(2),
        ]);

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) use ($previous): void {
            $mock->shouldReceive('getRecording')
                ->once()
                ->withArgs(fn (Webinar $webinar): bool => $webinar->is($previous))
                ->andReturn(new ProviderRecordingData(
                    playbackUrl: 'https://zoom.example.test/replay/approved',
                    playbackPasscode: '1234',
                ));
        });

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('forWebinar')->once()->andReturn($provider);
        });

        $result = app(ReviewWebinarPostEventAction::class)->handle(
            webinar: $current,
            playbackMode: ReviewWebinarPostEventAction::MODE_ALTERNATE,
            alternateWebinar: $previous,
            reviewedByUserId: 99,
        );

        $this->assertSame('https://zoom.example.test/replay/approved', $result->playback_url);
        $this->assertSame('1234', $result->playback_passcode);
        $this->assertSame('approved', data_get($result->meta, 'normalized.post_event.review.status'));
        $this->assertSame('alternate', data_get($result->meta, 'normalized.post_event.review.playback_mode'));
        $this->assertSame($previous->id, data_get($result->meta, 'normalized.post_event.review.source_webinar_id'));
        $this->assertSame(99, data_get($result->meta, 'normalized.post_event.review.reviewed_by_user_id'));
    }

    public function test_operator_cannot_approve_replay_while_attendance_is_still_syncing(): void
    {
        Config::set('webinars.post_event.review.required', true);
        Config::set('webinars.post_event.attendance.enabled', true);

        $webinar = Webinar::factory()->create([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Attendance is still syncing.');

        app(ReviewWebinarPostEventAction::class)->handle(
            webinar: $webinar,
            playbackMode: ReviewWebinarPostEventAction::MODE_CURRENT,
        );
    }

    public function test_operator_can_explicitly_suppress_replay_without_provider_lookup(): void
    {
        Config::set('webinars.post_event.review.required', true);

        $webinar = Webinar::factory()->create([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forWebinar');
        });

        $result = app(ReviewWebinarPostEventAction::class)->handle(
            webinar: $webinar,
            playbackMode: ReviewWebinarPostEventAction::MODE_NONE,
            reviewedByUserId: 7,
        );

        $this->assertSame('suppressed', data_get($result->meta, 'normalized.post_event.review.status'));
        $this->assertSame('none', data_get($result->meta, 'normalized.post_event.review.playback_mode'));
        $this->assertNull(data_get($result->meta, 'normalized.post_event.review.source_webinar_id'));
    }
}