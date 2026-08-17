<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarCrmWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_exposes_pending_follow_up_reviews_and_upcoming_webinar_state(): void
    {
        Config::set('webinars.post_event.review.required', true);

        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Game Plan',
            'slug' => 'homebuyer-game-plan',
        ]);

        $completed = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'title' => 'Homebuyer Game Plan - Completed',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => [
                'normalized' => [
                    'post_event' => [
                        'review' => [
                            'status' => 'pending',
                        ],
                    ],
                ],
            ],
        ]);

        WebinarRegistration::factory()->count(2)->create([
            'webinar_id' => $completed->id,
            'status' => 'attended',
            'attended_at' => now()->subHour(),
        ]);
        WebinarRegistration::factory()->create([
            'webinar_id' => $completed->id,
            'status' => 'missed',
            'attended_at' => null,
        ]);

        $upcoming = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'title' => 'Homebuyer Game Plan - August',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
        ]);
        WebinarRegistration::factory()->create([
            'webinar_id' => $upcoming->id,
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('pendingPostEventReviews', function (Collection $reviews) use ($completed): bool {
                if ($reviews->count() !== 1) {
                    return false;
                }

                $review = $reviews->first();

                return $review instanceof Webinar
                    && $review->is($completed)
                    && (int) $review->attended_registrations_count === 2
                    && (int) $review->missed_registrations_count === 1;
            })
            ->assertViewHas('upcomingWebinars', function (Collection $webinars) use ($upcoming): bool {
                $webinar = $webinars->first(
                    fn (Webinar $candidate): bool => $candidate->is($upcoming),
                );

                return $webinar instanceof Webinar
                    && (int) $webinar->registrations_count === 1;
            })
            ->assertViewHas('registrationAttentionCount', 0)
            ->assertViewHas('attentionCount', 1);
    }

    public function test_workspace_exposes_registration_recovery_as_attention_state(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'title' => 'Registration Recovery Webinar',
        ]);

        WebinarRegistration::factory()->create([
            'webinar_id' => $webinar->id,
            'meta' => [
                'registration_finalization' => [
                    'status' => 'failed',
                    'failure_reason' => 'provider_timeout',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['attention' => 1]))
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('showAttention', true)
            ->assertViewHas('registrationAttentionCount', 1)
            ->assertViewHas('attentionCount', 1)
            ->assertViewHas('webinars', fn (Collection $webinars): bool => $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($webinar),
            ));
    }
}