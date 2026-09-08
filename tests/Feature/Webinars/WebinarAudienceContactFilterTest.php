<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\Contacts\Filters\WebinarAttendanceContactFilterCriterion;
use App\Modules\Webinars\Services\WebinarProviderSchedulePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarAudienceContactFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_series_session_and_series_scoped_range_filters_resolve_historical_outcomes(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'audience-filter-series',
            'title' => 'Audience Filter Series',
        ]);
        $otherSeries = WebinarSeries::factory()->create([
            'slug' => 'other-audience-series',
            'title' => 'Other Audience Series',
        ]);

        $first = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHour(),
        ]);
        $second = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
        $otherEarlier = Webinar::factory()->for($otherSeries, 'webinarSeries')->create([
            'starts_at' => now()->subDays(4),
            'ends_at' => now()->subDays(4)->addHour(),
        ]);

        $attendedFirst = Contact::factory()->create();
        $missedSecond = Contact::factory()->create();
        $otherAttendee = Contact::factory()->create();

        $this->registration($attendedFirst, $first, 'attended');
        $this->registration($missedSecond, $second, 'missed');
        $this->registration($otherAttendee, $otherEarlier, 'attended');

        $resolver = app(ContactFilterResolver::class);

        $anyAttended = $resolver->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['any:attended']],
        ])->pluck('id')->sort()->values()->all();

        $seriesMissed = $resolver->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['series:audience-filter-series:missed']],
        ])->pluck('id')->all();

        $sessionAttended = $resolver->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['session:'.$first->getKey().':attended']],
        ])->pluck('id')->all();

        $beforeSecondAttended = $resolver->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['before_session:'.$second->getKey().':attended']],
        ])->pluck('id')->all();

        $onOrAfterFirstMissed = $resolver->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['on_or_after_session:'.$first->getKey().':missed']],
        ])->pluck('id')->all();

        $expectedAny = [$attendedFirst->getKey(), $otherAttendee->getKey()];
        sort($expectedAny);

        $this->assertSame($expectedAny, $anyAttended);
        $this->assertSame([$missedSecond->getKey()], $seriesMissed);
        $this->assertSame([$attendedFirst->getKey()], $sessionAttended);
        $this->assertSame([$attendedFirst->getKey()], $beforeSecondAttended);
        $this->assertSame([$missedSecond->getKey()], $onOrAfterFirstMissed);
        $this->assertNotContains($otherAttendee->getKey(), $beforeSecondAttended);
    }

    public function test_hidden_occurrences_do_not_contribute_to_historical_audience_filters(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'visible-history-series',
            'title' => 'Visible History Series',
        ]);

        $visible = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
        $hidden = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
            'hidden_at' => now()->subHours(12),
            'hidden_reason' => 'operator_removed',
        ]);

        $visibleContact = Contact::factory()->create();
        $hiddenContact = Contact::factory()->create();
        $this->registration($visibleContact, $visible, 'attended');
        $this->registration($hiddenContact, $hidden, 'attended');

        $ids = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => ['webinar_attendance' => ['any:attended']],
        ])->pluck('id')->all();

        $this->assertSame([$visibleContact->getKey()], $ids);
    }

    public function test_webinar_filter_presentation_uses_canonical_history_and_hides_flat_campaign_outcome_filter(): void
    {
        Config::set('webinars.providers.zoom.event_types.meeting.schedule_increment_minutes', 15);

        $series = WebinarSeries::factory()->meeting()->create([
            'slug' => 'canonical-history-series',
            'title' => 'Canonical History Series',
        ]);
        $slot = now()->subDays(2)->startOfHour();

        $oldProviderOccurrence = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => $slot,
            'ends_at' => $slot->copy()->addHour(),
            'provider_event_type' => 'webinar',
        ]);
        $canonicalMeeting = Webinar::factory()->meeting()->for($series, 'webinarSeries')->create([
            'starts_at' => $slot,
            'ends_at' => $slot->copy()->addHour(),
        ]);
        $offGrid = Webinar::factory()->meeting()->for($series, 'webinarSeries')->create([
            'starts_at' => $slot->copy()->addMinutes(7),
            'ends_at' => $slot->copy()->addMinutes(67),
            'meta' => [
                'provider' => [
                    'data' => [
                        'schedule_source' => WebinarProviderSchedulePolicy::PROVIDER_LIST_SCHEDULE_SOURCE,
                    ],
                ],
            ],
        ]);

        $oldSlotContact = Contact::factory()->create();
        $newSlotContact = Contact::factory()->create();
        $this->registration($oldSlotContact, $oldProviderOccurrence, 'attended');
        $this->registration($newSlotContact, $canonicalMeeting, 'attended');

        $criterion = app(WebinarAttendanceContactFilterCriterion::class);
        $presentationSessions = data_get(
            $criterion->presentation(),
            'audience_builder.sessions',
            [],
        );
        $sessionIds = array_map(
            static fn (array $session): int => (int) $session['id'],
            $presentationSessions,
        );

        $this->assertSame([$canonicalMeeting->getKey()], $sessionIds);
        $this->assertNotContains($oldProviderOccurrence->getKey(), $sessionIds);
        $this->assertNotContains($offGrid->getKey(), $sessionIds);

        $slotAudienceIds = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [
                'webinar_attendance' => [
                    'session:'.$canonicalMeeting->getKey().':attended',
                ],
            ],
        ])->pluck('id')->sort()->values()->all();
        $expectedSlotAudienceIds = [
            $oldSlotContact->getKey(),
            $newSlotContact->getKey(),
        ];
        sort($expectedSlotAudienceIds);

        $this->assertSame($expectedSlotAudienceIds, $slotAudienceIds);

        $fallbackValues = array_column($criterion->options(), 'value');
        $this->assertContains('session:'.$canonicalMeeting->getKey().':attended', $fallbackValues);
        $this->assertFalse((bool) array_filter(
            $fallbackValues,
            static fn (string $value): bool => str_starts_with($value, 'before_session:'),
        ));

        $definitions = collect(app(ContactFilterCriterionRegistry::class)->definitions())
            ->keyBy('key');

        $this->assertFalse((bool) data_get(
            $definitions->get('webinar_outcome'),
            'presentation.audience_builder.visible',
            true,
        ));
        $this->assertFalse((bool) data_get(
            $definitions->get('webinar_outcome'),
            'presentation.contact_index.visible',
            true,
        ));
        $this->assertSame(
            'webinars.audience-filter',
            data_get(
                $definitions->get('webinar_attendance'),
                'presentation.audience_builder.component',
            ),
        );
    }

    public function test_webinar_type_and_session_surfaces_link_to_exact_contact_filters(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'slug' => 'deep-link-series',
            'title' => 'Deep Link Series',
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $series))
            ->assertOk()
            ->assertSee(route('crm.contacts.index', [
                'webinar_attendance' => 'series:deep-link-series:attended',
            ]), false)
            ->assertSee(route('crm.contacts.index', [
                'webinar_attendance' => 'series:deep-link-series:missed',
            ]), false);

        $this->actingAs($user)
            ->get(route('crm.webinars.show', $webinar))
            ->assertOk()
            ->assertSee(route('crm.contacts.index', [
                'webinar_attendance' => 'session:'.$webinar->getKey().':attended',
            ]), false)
            ->assertSee(route('crm.contacts.index', [
                'webinar_attendance' => 'session:'.$webinar->getKey().':missed',
            ]), false);
    }

    public function test_fallback_options_keep_global_type_and_canonical_session_choices_without_flat_range_labels(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'choice-series',
            'title' => 'Choice Series',
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
        ]);

        $values = array_column(
            app(WebinarAttendanceContactFilterCriterion::class)->options(),
            'value',
        );

        $this->assertContains('any:attended', $values);
        $this->assertContains('any:missed', $values);
        $this->assertContains('series:choice-series:attended', $values);
        $this->assertContains('series:choice-series:missed', $values);
        $this->assertContains('session:'.$webinar->getKey().':attended', $values);
        $this->assertContains('session:'.$webinar->getKey().':missed', $values);
        $this->assertFalse((bool) array_filter(
            $values,
            static fn (string $value): bool => str_starts_with($value, 'before_session:')
                || str_starts_with($value, 'on_or_after_session:'),
        ));
    }

    private function registration(Contact $contact, Webinar $webinar, string $status): WebinarRegistration
    {
        return WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create([
                'status' => $status,
                'webinar_slug' => $webinar->slug,
                'attended_at' => $status === 'attended' ? now() : null,
            ]);
    }
}