<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Data\WebinarSeriesRemovalPlan;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarSeriesLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.enabled', [
            'core',
            'messaging',
            'webinars',
        ]);
    }

    public function test_empty_webinar_type_is_permanently_deleted(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Unused Webinar Type',
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinar-series.destroy', $series))
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('webinar_series', [
            'id' => $series->getKey(),
        ]);
    }

    public function test_webinar_type_with_session_history_is_archived_and_restorable(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Past Client Workshop',
            'slug' => 'past-client-workshop',
            'status' => 'active',
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create();
        $registration = WebinarRegistration::factory()->for($webinar)->create();

        $this->actingAs($user)
            ->delete(route('crm.webinar-series.destroy', $series))
            ->assertRedirect(route('crm.webinar-series.index', [
                'archived_types' => 1,
            ]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinar_series', [
            'id' => $series->getKey(),
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'webinar_series_id' => $series->getKey(),
        ]);
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => $registration->getKey(),
            'webinar_id' => $webinar->getKey(),
        ]);
        $this->assertFalse(
            app(GetActiveWebinarSeriesAction::class)
                ->handle()
                ->contains(fn (WebinarSeries $candidate): bool => $candidate->is($series)),
        );

        $this->actingAs($user)
            ->patch(route('crm.webinar-series.restore', $series))
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinar_series', [
            'id' => $series->getKey(),
            'status' => 'active',
        ]);
        $this->assertTrue(
            app(GetActiveWebinarSeriesAction::class)
                ->handle()
                ->contains(fn (WebinarSeries $candidate): bool => $candidate->is($series)),
        );
    }

    public function test_waitlist_or_removed_provider_history_prevents_hard_delete(): void
    {
        $user = User::factory()->create();
        $waitlistSeries = WebinarSeries::factory()->create([
            'title' => 'Waitlist History',
        ]);
        WebinarWaitlistSignup::factory()->create([
            'webinar_series_id' => $waitlistSeries->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinar-series.destroy', $waitlistSeries))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinar_series', [
            'id' => $waitlistSeries->getKey(),
            'status' => 'inactive',
        ]);

        $suppressedSeries = WebinarSeries::factory()->create([
            'title' => 'Suppressed Provider History',
        ]);
        WebinarOccurrenceSuppression::query()->create([
            'webinar_series_id' => $suppressedSeries->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $suppressedSeries->providerEventTypeKey(),
            'external_id' => 'zoom-removed-series-history',
            'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
            'suppressed_at' => now(),
            'meta' => [],
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinar-series.destroy', $suppressedSeries))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinar_series', [
            'id' => $suppressedSeries->getKey(),
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('webinar_occurrence_suppressions', [
            'webinar_series_id' => $suppressedSeries->getKey(),
            'external_id' => 'zoom-removed-series-history',
        ]);
    }

    public function test_directory_separates_active_and_archived_types_and_detail_exposes_removal_plan(): void
    {
        $user = User::factory()->create();
        $active = WebinarSeries::factory()->create([
            'title' => 'Active Type',
            'status' => 'active',
        ]);
        $archived = WebinarSeries::factory()->create([
            'title' => 'Archived Type',
            'status' => 'inactive',
        ]);
        Webinar::factory()->for($active, 'webinarSeries')->create();

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('showArchivedTypes', false)
            ->assertViewHas('archivedTypeCount', 1)
            ->assertViewHas('series', function (Collection $series) use ($active, $archived): bool {
                return $series->contains(fn (WebinarSeries $candidate): bool => $candidate->is($active))
                    && ! $series->contains(fn (WebinarSeries $candidate): bool => $candidate->is($archived));
            });

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['archived_types' => 1]))
            ->assertOk()
            ->assertViewHas('showArchivedTypes', true)
            ->assertViewHas('series', function (Collection $series) use ($active, $archived): bool {
                return ! $series->contains(fn (WebinarSeries $candidate): bool => $candidate->is($active))
                    && $series->contains(fn (WebinarSeries $candidate): bool => $candidate->is($archived));
            });

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $active))
            ->assertOk()
            ->assertViewHas('seriesRemovalPlan', function (mixed $plan): bool {
                return $plan instanceof WebinarSeriesRemovalPlan
                    && ! $plan->canDelete()
                    && $plan->sessionCount === 1;
            });

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $archived))
            ->assertOk()
            ->assertSee(route('crm.webinar-series.restore', $archived), false);
    }
}