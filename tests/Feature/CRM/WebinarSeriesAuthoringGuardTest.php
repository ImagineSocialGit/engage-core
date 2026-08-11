<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarSeriesAuthoringGuardTest extends TestCase
{
    use RefreshDatabase;

    private const EXISTING_SERIES_GUIDANCE = 'A webinar series with this title or public slug already exists. Use that series instead: choose its Zoom event type, then sync it. Occurrences of the other provider event type become historical automatically. Use occurrence replacement only when registrations must move to a replacement occurrence.';

    public function test_exact_existing_series_title_blocks_second_provider_event_type_and_guides_operator(): void
    {
        $user = User::factory()->create();

        WebinarSeries::query()->create([
            'title' => 'Homebuyer Game Plan',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('crm.webinar-series.index'))
            ->post(route('crm.webinar-series.store'), [
                'title' => 'Homebuyer Game Plan',
                'provider_event_type' => WebinarProviderEventType::Meeting->value,
            ]);

        $response
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHasErrors([
                'title' => self::EXISTING_SERIES_GUIDANCE,
            ]);

        $this->assertDatabaseCount('webinar_series', 1);
    }

    public function test_slug_equivalent_title_variant_is_blocked_before_database_unique_failure(): void
    {
        $user = User::factory()->create();

        WebinarSeries::query()->create([
            'title' => 'Homebuyer Game Plan',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('crm.webinar-series.index'))
            ->post(route('crm.webinar-series.store'), [
                'title' => '  Homebuyer   Game Plan!  ',
                'provider_event_type' => WebinarProviderEventType::Meeting->value,
            ]);

        $response
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHasErrors([
                'title' => self::EXISTING_SERIES_GUIDANCE,
            ]);

        $this->assertDatabaseCount('webinar_series', 1);
    }

    public function test_distinct_series_identity_can_still_be_created(): void
    {
        $user = User::factory()->create();

        WebinarSeries::query()->create([
            'title' => 'Homebuyer Game Plan',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.webinar-series.store'), [
                'title' => '  VA Homebuyer Game Plan  ',
                'provider_event_type' => '  MEETING  ',
            ]);

        $response
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('webinar_series', [
            'title' => 'VA Homebuyer Game Plan',
            'slug' => 'va-homebuyer-game-plan',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);
    }

    public function test_public_slug_resolution_returns_the_single_matching_active_series(): void
    {
        $series = new WebinarSeries([
            'title' => 'Homebuyer Game Plan',
            'slug' => 'homebuyer-game-plan',
            'status' => 'active',
        ]);
        $series->setAttribute('id', 10);

        $action = new class(new Collection([$series])) extends GetActiveWebinarSeriesAction
        {
            public function __construct(
                private readonly Collection $series,
            ) {}

            public function handle(): Collection
            {
                return $this->series;
            }
        };

        $this->assertSame(
            $series,
            $action->findBySlug('homebuyer-game-plan'),
        );
    }

    public function test_public_slug_resolution_fails_closed_for_legacy_duplicate_active_series(): void
    {
        $webinarSeries = new WebinarSeries([
            'title' => 'Homebuyer Game Plan',
            'slug' => 'homebuyer-game-plan',
            'status' => 'active',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);
        $webinarSeries->setAttribute('id', 10);

        $meetingSeries = new WebinarSeries([
            'title' => 'Homebuyer Game Plan',
            'slug' => 'homebuyer-game-plan',
            'status' => 'active',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);
        $meetingSeries->setAttribute('id', 11);

        $action = new class(new Collection([
            $webinarSeries,
            $meetingSeries,
        ])) extends GetActiveWebinarSeriesAction
        {
            public function __construct(
                private readonly Collection $series,
            ) {}

            public function handle(): Collection
            {
                return $this->series;
            }
        };

        $this->assertNull(
            $action->findBySlug('homebuyer-game-plan'),
        );
    }
}