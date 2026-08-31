<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Reporting\PaidAdTrackingLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarLinkModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_ad_tracking_generator_matches_supported_reporting_patterns(): void
    {
        $platforms = app(PaidAdTrackingLinkGenerator::class)->platforms();

        $this->assertSame(
            'utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&engage_platform=meta&engage_campaign_id={{campaign.id}}&engage_group_id={{adset.id}}&engage_creative_id={{ad.id}}&engage_placement={{placement}}',
            $platforms['meta']['parameters'],
        );
        $this->assertSame(
            'utm_source=tiktok&utm_medium=paid_social&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID_NAME__&utm_term=__AID_NAME__&engage_platform=tiktok&engage_campaign_id=__CAMPAIGN_ID__&engage_group_id=__AID__&engage_creative_id=__CID__&engage_placement=__PLACEMENT__',
            $platforms['tiktok']['parameters'],
        );
        $this->assertSame(
            'utm_source=youtube&utm_medium=paid_video&utm_campaign={_engcamp}&utm_content={_engad}&utm_term={_enggroup}&engage_platform=google_ads&engage_campaign_id={campaignid}&engage_group_id={adgroupid}&engage_creative_id={creative}&engage_placement={placement}',
            $platforms['youtube']['parameters'],
        );
        $this->assertSame([
            [
                'level' => 'Campaign',
                'key' => '_engcamp',
                'value_hint' => 'Campaign name',
            ],
            [
                'level' => 'Ad group',
                'key' => '_enggroup',
                'value_hint' => 'Ad group name',
            ],
            [
                'level' => 'Ad',
                'key' => '_engad',
                'value_hint' => 'Ad / creative label',
            ],
        ], $platforms['youtube']['custom_parameters']);
    }

    public function test_paid_ad_tracking_generator_uses_reporting_external_attribution_keys(): void
    {
        Config::set('reporting.attribution.external_keys', [
            'platform' => 'paid_platform',
            'campaign_id' => 'paid_campaign',
            'group_id' => 'paid_group',
            'creative_id' => 'paid_creative',
            'placement' => 'paid_placement',
        ]);

        $platforms = app(PaidAdTrackingLinkGenerator::class)->platforms();

        $this->assertSame(
            'utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&paid_platform=meta&paid_campaign={{campaign.id}}&paid_group={{adset.id}}&paid_creative={{ad.id}}&paid_placement={{placement}}',
            $platforms['meta']['parameters'],
        );
    }

    public function test_workspace_limits_upcoming_panel_to_two_and_exposes_get_links_for_each_webinar(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'webinars',
            'reporting',
        ]);

        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Class',
            'slug' => 'homebuyer-class',
        ]);

        $first = $this->futureWebinar($series, 'September Class', 1);
        $second = $this->futureWebinar($series, 'October Class', 2);
        $third = $this->futureWebinar($series, 'November Class', 3);

        $response = $this->actingAs($user)
            ->get(route('crm.webinar-series.index'));

        $response
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('upcomingWebinars', function (Collection $webinars) use ($first, $second, $third): bool {
                return $webinars->count() === 2
                    && $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($first))
                    && $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($second))
                    && ! $webinars->contains(fn (Webinar $webinar): bool => $webinar->is($third));
            })
            ->assertViewHas('webinarLinkOptions', function (Collection $options) use ($first, $second, $third): bool {
                foreach ([$first, $second, $third] as $webinar) {
                    $option = $options->get((string) $webinar->getKey());

                    if (! is_array($option)
                        || $option['webinar_id'] !== (int) $webinar->getKey()
                        || $option['destination_url'] !== route('webinar.show', ['seriesSlug' => 'homebuyer-class'])
                    ) {
                        return false;
                    }
                }

                return true;
            })
            ->assertViewHas('paidAdTrackingPlatforms', fn (array $platforms): bool => array_keys($platforms) === [
                'meta',
                'tiktok',
                'youtube',
            ])
            ->assertSee('data-webinar-links-modal', false)
            ->assertSee('data-webinar-get-links="'.$first->getKey().'"', false)
            ->assertSee('x-on:click="openLinksModal('.$first->getKey().')"', false)
            ->assertSee('Copy Link')
            ->assertSee('Get Links')
            ->assertSee('Get ad links for reporting')
            ->assertSee('data-webinar-ad-reporting-links', false);

        $this->assertGreaterThanOrEqual(
            2,
            substr_count(
                $response->getContent(),
                'data-webinar-get-links="'.$first->getKey().'"',
            ),
        );
    }

    public function test_get_links_remains_available_when_reporting_is_disabled(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'webinars',
        ]);

        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Class',
            'slug' => 'homebuyer-class',
        ]);
        $webinar = $this->futureWebinar($series, 'September Class', 1);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('webinarLinkOptions', fn (Collection $options): bool => $options->has(
                (string) $webinar->getKey(),
            ))
            ->assertViewHas('paidAdTrackingPlatforms', [])
            ->assertSee('data-webinar-links-modal', false)
            ->assertSee('data-webinar-get-links="'.$webinar->getKey().'"', false)
            ->assertSee('Get Links')
            ->assertDontSee('data-webinar-ad-reporting-section', false)
            ->assertDontSee('Get ad links for reporting');
    }

    private function futureWebinar(
        WebinarSeries $series,
        string $title,
        int $daysFromNow,
    ): Webinar {
        return Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => $title,
            'starts_at' => now()->addDays($daysFromNow),
            'ends_at' => now()->addDays($daysFromNow)->addHour(),
        ]);
    }
}