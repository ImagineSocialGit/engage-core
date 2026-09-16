<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\ModuleIntegrations\Scheduling\Webinars\WebinarRegistrantBookingEligibilityProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarRegistrantBookingEligibilityProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_provider_qualifies_registration_for_most_recent_started_webinar_in_series(): void
    {
        CarbonImmutable::setTestNow('2026-09-16 20:00:00 UTC');
        $contact = Contact::factory()->create(['email' => 'registrant@example.test']);
        $series = WebinarSeries::factory()->create(['title' => 'Homebuyer Webinar']);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Older Webinar',
            'starts_at' => CarbonImmutable::parse('2026-09-09 18:00:00 UTC'),
        ]);
        $current = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Current Webinar',
            'starts_at' => CarbonImmutable::parse('2026-09-16 18:00:00 UTC'),
        ]);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Future Webinar',
            'starts_at' => CarbonImmutable::parse('2026-09-23 18:00:00 UTC'),
        ]);
        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $current->getKey(),
            'status' => 'registered',
            'cancelled_at' => null,
        ]);

        $identity = app(WebinarRegistrantBookingEligibilityProvider::class)->resolve(
            criteria: [
                'series_id' => $series->getKey(),
                'occurrence' => 'latest_started',
            ],
            email: 'REGISTRANT@example.test',
            evaluatedAt: CarbonImmutable::now('UTC'),
        );

        $this->assertSame($contact->getKey(), $identity?->contactId);
        $this->assertSame('webinar:'.$current->getKey(), $identity?->scopeKey);
        $this->assertSame(
            $registration->getKey(),
            $identity?->meta['webinar_registration_id'] ?? null,
        );
    }

    public function test_registration_for_an_older_or_future_occurrence_does_not_qualify_for_latest_started_scope(): void
    {
        CarbonImmutable::setTestNow('2026-09-16 20:00:00 UTC');
        $contact = Contact::factory()->create(['email' => 'older@example.test']);
        $series = WebinarSeries::factory()->create();
        $older = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => CarbonImmutable::parse('2026-09-09 18:00:00 UTC'),
        ]);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => CarbonImmutable::parse('2026-09-16 18:00:00 UTC'),
        ]);
        $future = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => CarbonImmutable::parse('2026-09-23 18:00:00 UTC'),
        ]);
        WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $older->getKey(),
            'status' => 'registered',
            'cancelled_at' => null,
        ]);
        WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $future->getKey(),
            'status' => 'registered',
            'cancelled_at' => null,
        ]);

        $identity = app(WebinarRegistrantBookingEligibilityProvider::class)->resolve(
            criteria: [
                'series_id' => $series->getKey(),
                'occurrence' => 'latest_started',
            ],
            email: 'older@example.test',
            evaluatedAt: CarbonImmutable::now('UTC'),
        );

        $this->assertNull($identity);
    }

    public function test_cancelled_registration_for_latest_started_webinar_does_not_qualify(): void
    {
        CarbonImmutable::setTestNow('2026-09-16 20:00:00 UTC');
        $contact = Contact::factory()->create(['email' => 'cancelled@example.test']);
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => CarbonImmutable::parse('2026-09-16 18:00:00 UTC'),
        ]);
        WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $identity = app(WebinarRegistrantBookingEligibilityProvider::class)->resolve(
            criteria: [
                'series_id' => $series->getKey(),
                'occurrence' => 'latest_started',
            ],
            email: 'cancelled@example.test',
            evaluatedAt: CarbonImmutable::now('UTC'),
        );

        $this->assertNull($identity);
    }
}