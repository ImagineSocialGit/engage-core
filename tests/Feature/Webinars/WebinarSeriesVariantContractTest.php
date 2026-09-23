<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\ZoomWebinarService;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\ReplaceWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\ResolvePublicWebinarSeriesVariantAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use LogicException;
use Tests\TestCase;

class WebinarSeriesVariantContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_series_gets_primary_variant_without_changing_its_public_url_identity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.webinar-series.store'), [
                'title' => 'VA Homebuyer Masterclass',
                'provider_event_type' => 'meeting',
            ])
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHasNoErrors();

        $series = WebinarSeries::query()->sole();
        $variant = WebinarSeriesVariant::query()
            ->where('webinar_series_id', $series->getKey())
            ->sole();

        $this->assertTrue($variant->is_default);
        $this->assertSame($series->slug, $variant->public_slug);
        $this->assertSame($series->title, $variant->provider_match_title);
        $this->assertSame($series->providerEventTypeKey(), $variant->providerEventTypeKey());
    }

    public function test_primary_variant_public_slug_cannot_be_moved_away_from_existing_series_url(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'VA Homebuyer Masterclass',
            'slug' => 'va-homebuyer',
            'provider_event_type' => 'meeting',
        ]);
        $variant = WebinarSeriesVariant::factory()->defaultVariant()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'public_slug' => $series->slug,
            'timezone' => 'America/Chicago',
            'provider_event_type' => 'meeting',
            'provider_match_title' => 'VA Homebuyer Masterclass Central',
        ]);

        $this->actingAs($user)
            ->from(route('crm.webinar-series.show', $series))
            ->patch(route('crm.webinar-series.variants.update', [$series, $variant]), [
                'name' => 'Central',
                'public_slug' => 'replacement-url',
                'timezone' => 'America/Chicago',
                'provider_event_type' => 'meeting',
                'provider_match_title' => 'VA Homebuyer Masterclass Central',
                'status' => 'active',
            ])
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHasErrors('public_slug');

        $this->assertSame(
            'va-homebuyer',
            $variant->refresh()->public_slug,
        );
    }

    public function test_primary_variant_model_preserves_original_public_url_and_active_state_for_active_series(): void
    {
        $series = WebinarSeries::factory()->create([
            'title' => 'VA Homebuyer Masterclass',
            'slug' => 'va-homebuyer',
            'status' => 'active',
        ]);
        $variant = WebinarSeriesVariant::factory()->defaultVariant()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'public_slug' => $series->slug,
            'timezone' => 'America/Chicago',
            'meta' => [
                'compatibility' => [
                    'original_public_slug' => $series->slug,
                ],
            ],
        ]);

        $variant->forceFill([
            'public_slug' => 'replacement-url',
            'status' => 'inactive',
        ])->save();

        $variant->refresh();

        $this->assertSame('va-homebuyer', $variant->public_slug);
        $this->assertSame('active', $variant->status);
    }

    public function test_public_variant_slugs_resolve_to_distinct_markets_under_one_canonical_series(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer',
            'status' => 'active',
        ]);
        $central = WebinarSeriesVariant::factory()->defaultVariant()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'public_slug' => 'va-homebuyer',
            'timezone' => 'America/Chicago',
            'provider_event_type' => $series->providerEventTypeKey(),
        ]);
        $eastern = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Eastern',
            'public_slug' => 'va-homebuyer-eastern',
            'timezone' => 'America/New_York',
            'provider_event_type' => $series->providerEventTypeKey(),
        ]);

        $resolver = app(ResolvePublicWebinarSeriesVariantAction::class);
        $resolvedCentral = $resolver->findByPublicSlug($central->public_slug);
        $resolvedEastern = $resolver->findByPublicSlug($eastern->public_slug);

        $this->assertSame($central->getKey(), $resolvedCentral?->getKey());
        $this->assertSame($eastern->getKey(), $resolvedEastern?->getKey());
        $this->assertSame($series->getKey(), $resolvedCentral?->webinar_series_id);
        $this->assertSame($series->getKey(), $resolvedEastern?->webinar_series_id);
    }

    public function test_registerable_occurrence_resolution_never_crosses_variant_boundaries(): void
    {
        $series = WebinarSeries::factory()->create(['status' => 'active']);
        $central = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'timezone' => 'America/Chicago',
            'provider_event_type' => $series->providerEventTypeKey(),
            'status' => 'active',
        ]);
        $eastern = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Eastern',
            'timezone' => 'America/New_York',
            'provider_event_type' => $series->providerEventTypeKey(),
            'status' => 'active',
        ]);
        $centralWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_series_variant_id' => $central->getKey(),
            'provider_event_type' => $central->providerEventTypeKey(),
            'timezone' => $central->timezone,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $easternWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_series_variant_id' => $eastern->getKey(),
            'provider_event_type' => $eastern->providerEventTypeKey(),
            'timezone' => $eastern->timezone,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $resolver = app(ResolveRegisterableWebinarAction::class);

        $this->assertSame(
            $centralWebinar->getKey(),
            $resolver->findForVariant($central, (int) $centralWebinar->getKey())?->getKey(),
        );
        $this->assertNull(
            $resolver->findForVariant($central, (int) $easternWebinar->getKey()),
        );
    }

    public function test_same_contact_can_wait_for_two_markets_without_merging_the_subscriptions(): void
    {
        $series = WebinarSeries::factory()->create();
        $central = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);
        $eastern = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);
        $contact = Contact::factory()->create();

        foreach ([$central, $eastern] as $variant) {
            WebinarWaitlistSignup::factory()->create([
                'contact_id' => $contact->getKey(),
                'webinar_series_id' => $series->getKey(),
                'webinar_series_variant_id' => $variant->getKey(),
            ]);
        }

        $this->assertSame(
            2,
            WebinarWaitlistSignup::query()
                ->where('webinar_series_id', $series->getKey())
                ->where('contact_id', $contact->getKey())
                ->count(),
        );
    }


    public function test_variant_sync_imports_only_the_matching_market_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00', 'UTC'));

        $series = WebinarSeries::factory()->create([
            'title' => 'VA Homebuyer Masterclass',
            'provider_event_type' => 'webinar',
            'status' => 'active',
        ]);
        $central = WebinarSeriesVariant::factory()->defaultVariant()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'public_slug' => $series->slug,
            'timezone' => 'America/Chicago',
            'provider_event_type' => 'webinar',
            'provider_match_title' => 'VA Homebuyer Masterclass',
            'status' => 'active',
        ]);

        $provider = Mockery::mock(ZoomWebinarService::class);
        $provider->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('VA Homebuyer Masterclass')
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                new ProviderWebinarData(
                    externalId: 'central-001',
                    title: 'VA Homebuyer Masterclass',
                    joinUrl: 'https://zoom.test/central',
                    registrationUrl: null,
                    startsAt: Carbon::parse('2026-09-24 19:00:00', 'America/Chicago')->utc(),
                    endsAt: Carbon::parse('2026-09-24 20:00:00', 'America/Chicago')->utc(),
                    timezone: 'America/Chicago',
                    description: null,
                    meta: [],
                ),
                new ProviderWebinarData(
                    externalId: 'eastern-001',
                    title: 'VA Homebuyer Masterclass',
                    joinUrl: 'https://zoom.test/eastern',
                    registrationUrl: null,
                    startsAt: Carbon::parse('2026-09-24 19:00:00', 'America/New_York')->utc(),
                    endsAt: Carbon::parse('2026-09-24 20:00:00', 'America/New_York')->utc(),
                    timezone: 'America/New_York',
                    description: null,
                    meta: [],
                ),
            ]));
        $this->app->instance(ZoomWebinarService::class, $provider);

        $result = app(SyncWebinarSeriesFromProviderAction::class)
            ->executeVariant($central);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['ignored_variant_mismatches']);

        $webinars = Webinar::query()->get();
        $this->assertCount(1, $webinars);
        $this->assertSame('central-001', $webinars->sole()->external_id);
        $this->assertSame($central->getKey(), $webinars->sole()->webinar_series_variant_id);
    }

    public function test_timezone_only_provider_correction_does_not_create_a_schedule_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));

        $series = WebinarSeries::factory()->create([
            'title' => 'VA Homebuyer Masterclass',
            'provider_event_type' => 'webinar',
            'status' => 'active',
        ]);
        $central = WebinarSeriesVariant::factory()->defaultVariant()->create([
            'webinar_series_id' => $series->getKey(),
            'name' => 'Central',
            'public_slug' => $series->slug,
            'timezone' => 'America/Chicago',
            'provider_event_type' => 'webinar',
            'provider_match_title' => 'VA Homebuyer Masterclass',
            'status' => 'active',
        ]);
        $startsAt = Carbon::parse('2026-09-24 19:00:00', 'America/Chicago')->utc();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_series_variant_id' => $central->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => 'webinar',
            'external_id' => 'central-existing-001',
            'timezone' => 'America/New_York',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);

        $provider = Mockery::mock(ZoomWebinarService::class);
        $provider->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('VA Homebuyer Masterclass')
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                new ProviderWebinarData(
                    externalId: 'central-existing-001',
                    title: 'VA Homebuyer Masterclass',
                    joinUrl: 'https://zoom.test/central-existing',
                    registrationUrl: null,
                    startsAt: $startsAt,
                    endsAt: $startsAt->copy()->addHour(),
                    timezone: 'America/Chicago',
                    description: null,
                    meta: [],
                ),
            ]));
        $this->app->instance(ZoomWebinarService::class, $provider);

        app(SyncWebinarSeriesFromProviderAction::class)->executeVariant($central);

        $this->assertSame('America/Chicago', $webinar->refresh()->timezone);
        $this->assertSame(0, WebinarScheduleChange::query()->count());
    }

    public function test_occurrence_replacement_cannot_move_registrations_between_markets(): void
    {
        $series = WebinarSeries::factory()->create();
        $central = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);
        $eastern = WebinarSeriesVariant::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);
        $source = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_series_variant_id' => $central->getKey(),
            'provider_event_type' => $central->providerEventTypeKey(),
        ]);
        $replacement = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_series_variant_id' => $eastern->getKey(),
            'provider_event_type' => $eastern->providerEventTypeKey(),
        ]);

        $this->expectException(LogicException::class);

        app(ReplaceWebinarOccurrenceAction::class)->handle(
            source: $source,
            replacement: $replacement,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

}