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

    public function test_directory_keeps_summary_attention_without_mixing_session_detail_into_the_surface(): void
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
            'title' => 'Homebuyer Game Plan - Next',
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
                $review = $reviews->first();

                return $reviews->count() === 1
                    && $review instanceof Webinar
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
            ->assertViewHas('attentionCount', 1)
            ->assertViewHas('series', fn (Collection $types): bool =>
                $types->contains(fn (WebinarSeries $type): bool => $type->is($series))
            );
    }

    public function test_directory_still_exposes_registration_recovery_as_attention_state(): void
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
            ->assertViewHas('showAttention', true)
            ->assertViewHas('registrationAttentionCount', 1)
            ->assertViewHas('attentionCount', 1)
            ->assertViewHas('webinars', fn (Collection $webinars): bool => $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($webinar),
            ));
    }

    public function test_provider_missing_session_moves_to_its_webinar_type_detail(): void
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
            ->assertViewHas('providerMissingCount', 1);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $series))
            ->assertOk()
            ->assertViewIs('crm.webinars.series-show')
            ->assertViewHas('providerMissingOccurrences', fn (Collection $sessions): bool =>
                $sessions->contains(fn (Webinar $candidate): bool => $candidate->is($missing))
            );
    }

    public function test_session_detail_is_the_owner_of_specific_registration_and_recovery_context(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create();
        $registration = WebinarRegistration::factory()->create([
            'webinar_id' => $webinar->getKey(),
            'meta' => [
                'registration_finalization' => [
                    'status' => 'reconciliation_required',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinars.show', $webinar))
            ->assertOk()
            ->assertViewIs('crm.webinars.show')
            ->assertViewHas('registrations', function ($registrations) use ($registration): bool {
                $loaded = $registrations->getCollection()->first();

                return $loaded instanceof WebinarRegistration
                    && $loaded->is($registration);
            })
            ->assertViewHas('registrationCounts', fn (array $counts): bool =>
                $counts['total'] === 1
            );
    }
}