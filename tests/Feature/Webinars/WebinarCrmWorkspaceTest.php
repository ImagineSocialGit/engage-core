<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus;
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
            ->assertViewHas('attentionCount', 1)
            ->assertSee('data-webinar-workspace-shell', false)
            ->assertSee('data-webinar-workspace-main', false)
            ->assertSee('data-webinar-workspace-attention', false)
            ->assertSee('data-upcoming-webinars-side-panel', false);
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

    public function test_workspace_surfaces_provider_missing_occurrence_and_removes_it_from_upcoming(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create();
        $missing = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Removed Zoom occurrence',
            'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Missing->value,
            'provider_missing_at' => now(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        WebinarRegistration::factory()->create([
            'webinar_id' => $missing->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('upcomingWebinars', fn (Collection $webinars): bool => ! $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($missing),
            ))
            ->assertViewHas('providerMissingCount', 1)
            ->assertViewHas('attentionCount', 1)
            ->assertSee('data-provider-missing-occurrence="'.$missing->getKey().'"', false);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['attention' => 1]))
            ->assertOk()
            ->assertViewHas('webinars', fn (Collection $webinars): bool => $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($missing),
            ));
    }

    public function test_workspace_exposes_remove_control_for_missing_occurrence_and_separates_series_setup_concerns(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create();
        $missing = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Missing->value,
            'provider_missing_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['attention' => 1]));

        $response
            ->assertOk()
            ->assertSee('data-webinar-remove-control="'.$missing->getKey().'"', false)
            ->assertSee('data-series-zoom-setup="'.$series->getKey().'"', false)
            ->assertSee('data-series-message-plan="'.$series->getKey().'"', false)
            ->assertSee('data-series-message-content="'.$series->getKey().'"', false);
    }
}