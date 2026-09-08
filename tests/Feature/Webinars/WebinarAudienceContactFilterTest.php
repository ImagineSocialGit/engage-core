<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\Contacts\Filters\WebinarAttendanceContactFilterCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarAudienceContactFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_series_and_specific_session_attendance_filters_resolve_historical_outcomes(): void
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
        $other = Webinar::factory()->for($otherSeries, 'webinarSeries')->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
        ]);

        $attendedFirst = Contact::factory()->create();
        $missedSecond = Contact::factory()->create();
        $otherAttendee = Contact::factory()->create();

        $this->registration($attendedFirst, $first, 'attended');
        $this->registration($missedSecond, $second, 'missed');
        $this->registration($otherAttendee, $other, 'attended');

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

        $expectedAny = [$attendedFirst->getKey(), $otherAttendee->getKey()];
        sort($expectedAny);

        $this->assertSame($expectedAny, $anyAttended);
        $this->assertSame([$missedSecond->getKey()], $seriesMissed);
        $this->assertSame([$attendedFirst->getKey()], $sessionAttended);
        $this->assertSame([$attendedFirst->getKey()], $beforeSecondAttended);
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

    public function test_options_include_global_type_and_session_choices(): void
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
        $this->assertContains('before_session:'.$webinar->getKey().':attended', $values);
        $this->assertContains('before_session:'.$webinar->getKey().':missed', $values);
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