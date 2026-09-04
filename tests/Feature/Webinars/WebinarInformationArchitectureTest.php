<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarRegistrationResponse;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarInformationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_type_detail_and_session_detail_have_separate_read_contracts(): void
    {
        Config::set('modules.enabled', [
            'core',
            'messaging',
            'webinars',
            'reporting',
        ]);

        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Game Plan',
            'slug' => 'homebuyer-game-plan',
        ]);

        $upcoming = Webinar::factory()->for($series, 'webinarSeries')->create([
            'title' => 'September Class',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        $past = Webinar::factory()->for($series, 'webinarSeries')->create([
            'title' => 'August Class',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
        $hidden = Webinar::factory()->for($series, 'webinarSeries')->create([
            'title' => 'Removed Class',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHour(),
            'hidden_at' => now(),
            'hidden_reason' => Webinar::HIDDEN_REASON_OPERATOR_REMOVED,
        ]);

        $contact = Contact::factory()->create([
            'email' => 'attendee@example.com',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($past)
            ->for($contact)
            ->create([
                'status' => 'attended',
                'attended_at' => now()->subDays(2),
                'meta' => [
                    'attendance' => [
                        'provider' => 'zoom',
                        'status' => 'attended',
                        'duration' => 2700,
                        'join_time' => now()->subDays(2)->toIso8601String(),
                        'leave_time' => now()->subDays(2)->addMinutes(45)->toIso8601String(),
                    ],
                ],
            ]);

        WebinarRegistrationResponse::query()->create([
            'webinar_registration_id' => $registration->getKey(),
            'question_key' => 'buying_timeline',
            'question_label' => 'When are you hoping to buy?',
            'question_type' => 'text',
            'answer_key' => 'within_90_days',
            'answer_label' => 'Within 90 days',
            'answer_text' => 'Within 90 days',
            'definition_version' => 'test-v1',
            'sort_order' => 10,
        ]);

        WebinarOccurrenceSuppression::query()->create([
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $series->providerEventTypeKey(),
            'external_id' => 'removed-zoom-123',
            'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
            'suppressed_at' => now(),
            'meta' => [
                'source_title' => 'Retirement Class',
                'source_starts_at' => now()->addWeek()->toIso8601String(),
            ],
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('series', function (Collection $types) use ($series): bool {
                $type = $types->firstWhere('id', $series->getKey());

                return $type instanceof WebinarSeries
                    && (int) $type->upcoming_sessions_count === 1
                    && (int) $type->past_sessions_count === 1
                    && (int) $type->removed_sessions_count === 1
                    && (int) $type->suppressed_sessions_count === 1;
            })
            ->assertSee(route('crm.webinar-series.show', $series), false);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $series))
            ->assertOk()
            ->assertViewIs('crm.webinars.series-show')
            ->assertViewHas('upcomingWebinars', fn (Collection $sessions): bool =>
                $sessions->contains(fn (Webinar $session): bool => $session->is($upcoming))
            )
            ->assertViewHas('historyWebinars', fn (Collection $sessions): bool =>
                $sessions->contains(fn (Webinar $session): bool => $session->is($past))
            )
            ->assertViewHas('removedWebinars', fn (Collection $sessions): bool =>
                $sessions->contains(fn (Webinar $session): bool => $session->is($hidden))
            )
            ->assertViewHas('registrationUrl', route('webinar.show', [
                'seriesSlug' => $series->slug,
            ]))
            ->assertSee(route('crm.webinars.show', $past), false);

        $this->actingAs($user)
            ->get(route('crm.webinars.show', $past))
            ->assertOk()
            ->assertViewIs('crm.webinars.show')
            ->assertViewHas('registrationCounts', [
                'total' => 1,
                'attended' => 1,
                'missed' => 0,
                'cancelled' => 0,
            ])
            ->assertViewHas('registrations', function ($registrations) use ($registration): bool {
                $loaded = $registrations->getCollection()->first();

                return $loaded instanceof WebinarRegistration
                    && $loaded->is($registration)
                    && $loaded->responses->count() === 1;
            });
    }

    public function test_removed_sessions_can_be_restored_from_the_webinar_type_surface(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Game Plan',
        ]);

        $hidden = Webinar::factory()->for($series, 'webinarSeries')->create([
            'hidden_at' => now(),
            'hidden_reason' => Webinar::HIDDEN_REASON_OPERATOR_REMOVED,
        ]);

        $suppression = WebinarOccurrenceSuppression::query()->create([
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $series->providerEventTypeKey(),
            'external_id' => 'removed-zoom-456',
            'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
            'suppressed_at' => now(),
            'meta' => [
                'source_title' => 'Removed Zoom Session',
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('crm.webinars.restore', $hidden))
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinars', [
            'id' => $hidden->getKey(),
            'hidden_at' => null,
            'hidden_reason' => null,
        ]);

        $this->actingAs($user)
            ->patch(route(
                'crm.webinar-occurrence-suppressions.restore',
                $suppression,
            ))
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('webinar_occurrence_suppressions', [
            'id' => $suppression->getKey(),
        ]);
    }
}