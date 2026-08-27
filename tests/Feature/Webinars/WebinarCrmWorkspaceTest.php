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
            ->assertSee('data-provider-missing-occurrence="'.$missing->getKey().'"', false)
            ->assertSee('Removed from Zoom');

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['attention' => 1]))
            ->assertOk()
            ->assertViewHas('webinars', fn (Collection $webinars): bool => $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($missing),
            ))
            ->assertSee('This event no longer exists in Zoom');
    }

    public function test_operator_can_keep_provider_missing_occurrence_for_history(): void
    {
        $user = User::factory()->create();
        $missing = Webinar::factory()->create([
            'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Missing->value,
            'provider_missing_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('crm.webinars.archive-missing', $missing))
            ->assertRedirect(route('crm.webinar-series.index', ['archived' => 1]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinars', [
            'id' => $missing->getKey(),
            'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Archived->value,
        ]);
        $this->assertNotNull($missing->refresh()->provider_archived_at);
    }
}