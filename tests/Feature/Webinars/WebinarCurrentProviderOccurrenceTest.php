<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebinarCurrentProviderOccurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_series_event_type_switch_immediately_stops_old_type_from_being_registerable(): void
    {
        $series = WebinarSeries::factory()->create([
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);
        $oldWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->assertTrue(
            app(GetNextUpcomingWebinarAction::class)
                ->getForSeries($series)?->is($oldWebinar) ?? false,
        );

        $series->forceFill([
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ])->save();

        $series->refresh();

        $this->assertNull(
            app(GetNextUpcomingWebinarAction::class)->getForSeries($series),
        );

        $meeting = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $this->assertTrue(
            app(GetNextUpcomingWebinarAction::class)
                ->getForSeries($series)?->is($meeting) ?? false,
        );
    }

    public function test_public_resolution_uses_only_the_series_current_provider_event_type(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'status' => 'active',
        ]);
        $oldWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $meeting = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);

        $resolver = app(ResolveRegisterableWebinarAction::class);

        $this->assertTrue($resolver->getForSeries($series)?->is($meeting) ?? false);
        $this->assertNull($resolver->findForSeries($series, $oldWebinar->getKey()));
        $this->assertFalse($resolver->isRegisterable($oldWebinar->load('webinarSeries')));
        $this->assertFalse($resolver->isRegisterableForSeries($oldWebinar, $series));
        $this->assertTrue($resolver->isRegisterableForSeries($meeting, $series));
    }

    public function test_global_public_resolution_ignores_an_earlier_occurrence_of_the_old_provider_type(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'status' => 'active',
        ]);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $meeting = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);

        $this->assertTrue(
            app(ResolveRegisterableWebinarAction::class)
                ->getGlobal()?->is($meeting) ?? false,
        );
    }

    public function test_crm_upcoming_hides_old_provider_type_but_archived_view_keeps_it_available(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->meeting()->create();
        $oldWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $meeting = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('webinars', function (Collection $webinars) use ($oldWebinar, $meeting): bool {
                return $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($meeting))
                    && ! $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($oldWebinar));
            });

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['archived' => 1]))
            ->assertOk()
            ->assertViewHas('webinars', function (Collection $webinars) use ($oldWebinar, $meeting): bool {
                return $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($meeting))
                    && $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($oldWebinar));
            });
    }
}