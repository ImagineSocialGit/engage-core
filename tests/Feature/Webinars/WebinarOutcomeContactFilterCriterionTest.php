<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\Contacts\Filters\WebinarOutcomeContactFilterCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarOutcomeContactFilterCriterionTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_expose_attended_and_missed_for_each_series(): void
    {
        WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer-game-plan',
            'title' => 'VA Homebuyer Game Plan',
        ]);

        $options = app(WebinarOutcomeContactFilterCriterion::class)->options();

        $this->assertEquals([
            'va-homebuyer-game-plan:attended',
            'va-homebuyer-game-plan:missed',
        ], array_column($options, 'value'));
    }

    public function test_normalization_preserves_semantically_valid_stale_series_values_and_rejects_invalid_shapes(): void
    {
        $criterion = app(WebinarOutcomeContactFilterCriterion::class);

        $this->assertEquals([
            'retired-series:attended',
            'va-homebuyer-game-plan:missed',
        ], $criterion->normalize([
            ' Retired-Series:ATTENDED ',
            'bad series:attended',
            'va-homebuyer-game-plan:unknown',
            'va-homebuyer-game-plan:missed',
            'va-homebuyer-game-plan:missed',
            null,
        ]));
    }

    public function test_latest_resolved_terminal_outcome_per_series_wins_and_nonterminal_registrations_do_not_erase_it(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer-game-plan',
            'title' => 'VA Homebuyer Game Plan',
        ]);

        $first = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subDays(14),
            'ends_at' => now()->subDays(14)->addHour(),
        ]);
        $second = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subDays(7)->addHour(),
        ]);
        $third = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
        ]);

        $latestMissed = Contact::withoutEvents(fn () => Contact::factory()->create());
        $latestAttended = Contact::withoutEvents(fn () => Contact::factory()->create());
        $pendingAfterAttended = Contact::withoutEvents(fn () => Contact::factory()->create());

        $this->registration($latestMissed, $first, 'attended');
        $this->registration($latestMissed, $second, 'missed');

        $this->registration($latestAttended, $first, 'missed');
        $this->registration($latestAttended, $second, 'attended');

        $this->registration($pendingAfterAttended, $first, 'attended');
        $this->registration($pendingAfterAttended, $third, 'registered');

        $criterion = app(WebinarOutcomeContactFilterCriterion::class);

        $attended = Contact::query();
        $criterion->apply($attended, ['va-homebuyer-game-plan:attended']);

        $missed = Contact::query();
        $criterion->apply($missed, ['va-homebuyer-game-plan:missed']);

        $this->assertEquals(
            [
                $latestAttended->getKey(),
                $pendingAfterAttended->getKey(),
            ],
            $attended->orderBy('contacts.id')->pluck('contacts.id')->all(),
        );

        $this->assertEquals(
            [$latestMissed->getKey()],
            $missed->orderBy('contacts.id')->pluck('contacts.id')->all(),
        );
    }

    private function registration(
        Contact $contact,
        Webinar $webinar,
        string $status,
    ): WebinarRegistration {
        return WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create([
                'status' => $status,
                'webinar_slug' => $webinar->slug,
                'registered_at' => $webinar->starts_at?->copy()->subDay() ?? now(),
                'attended_at' => $status === 'attended'
                    ? $webinar->starts_at
                    : null,
            ]);
    }
}