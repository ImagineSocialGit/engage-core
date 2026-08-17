<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarCrmWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_prioritizes_pending_follow_up_reviews_and_upcoming_webinars(): void
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
            ->assertSee('Webinar workspace')
            ->assertSee('Needs attention')
            ->assertSee('Homebuyer Game Plan - Completed')
            ->assertSee('2 attended · 1 missed')
            ->assertSee('Review follow-ups')
            ->assertSee('Upcoming webinars')
            ->assertSee('Homebuyer Game Plan - August')
            ->assertSee('1 registration')
            ->assertSee('Event details & recovery');
    }

    public function test_workspace_surfaces_registration_recovery_as_attention_work(): void
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
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertSee('1 item')
            ->assertSee('Registration recovery')
            ->assertSee('1 registration needs review')
            ->assertSee('Resolve registrations');
    }

    public function test_post_event_review_uses_business_facing_checkpoint_language(): void
    {
        Config::set('webinars.post_event.review.required', true);

        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'title' => 'VA Homebuyer Game Plan',
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

        $this->actingAs($user)
            ->get(route('crm.webinars.post-event-review.show', $webinar))
            ->assertOk()
            ->assertSee('Your webinar is finished — review follow-ups')
            ->assertSee('Follow-up checkpoint')
            ->assertSee('What should registrants receive next?')
            ->assertSee('Use this webinar’s replay')
            ->assertSee('Use a previous replay')
            ->assertSee('Do not send replay follow-ups')
            ->assertSee('Approve follow-up plan');
    }
}