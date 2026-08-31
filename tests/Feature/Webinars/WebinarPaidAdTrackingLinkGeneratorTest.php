<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Reporting\PaidAdTrackingSetupGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarPaidAdTrackingLinkGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_matches_the_paid_ad_tracking_contract_for_supported_platforms(): void
    {
        $setup = app(PaidAdTrackingSetupGenerator::class)
            ->generate('https://webinar.example.test/homebuyer-class');

        $this->assertSame(
            'https://webinar.example.test/homebuyer-class',
            $setup['destination_url'],
        );

        $this->assertSame(
            'utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&engage_platform=meta&engage_campaign_id={{campaign.id}}&engage_group_id={{adset.id}}&engage_creative_id={{ad.id}}&engage_placement={{placement}}',
            $setup['platforms']['meta']['parameters'],
        );

        $this->assertSame(
            'utm_source=tiktok&utm_medium=paid_social&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID_NAME__&utm_term=__AID_NAME__&engage_platform=tiktok&engage_campaign_id=__CAMPAIGN_ID__&engage_group_id=__AID__&engage_creative_id=__CID__&engage_placement=__PLACEMENT__',
            $setup['platforms']['tiktok']['parameters'],
        );

        $this->assertSame(
            'utm_source=youtube&utm_medium=paid_video&utm_campaign={_engcamp}&utm_content={_engad}&utm_term={_enggroup}&engage_platform=google_ads&engage_campaign_id={campaignid}&engage_group_id={adgroupid}&engage_creative_id={creative}&engage_placement={placement}',
            $setup['platforms']['youtube']['parameters'],
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
        ], $setup['platforms']['youtube']['custom_parameters']);
    }

    public function test_generator_uses_reporting_external_attribution_keys(): void
    {
        Config::set('reporting.attribution.external_keys', [
            'platform' => 'paid_platform',
            'campaign_id' => 'paid_campaign',
            'group_id' => 'paid_group',
            'creative_id' => 'paid_creative',
            'placement' => 'paid_placement',
        ]);

        $setup = app(PaidAdTrackingSetupGenerator::class)
            ->generate('https://webinar.example.test/homebuyer-class');

        $this->assertSame(
            'utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&paid_platform=meta&paid_campaign={{campaign.id}}&paid_group={{adset.id}}&paid_creative={{ad.id}}&paid_placement={{placement}}',
            $setup['platforms']['meta']['parameters'],
        );
    }

    public function test_webinar_workspace_exposes_tracking_setup_for_active_series_when_reporting_is_enabled(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'webinars',
            'reporting',
        ]);

        $user = User::factory()->create();
        $activeSeries = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Class',
            'slug' => 'homebuyer-class',
            'status' => 'active',
        ]);
        $inactiveSeries = WebinarSeries::factory()->create([
            'title' => 'Retired Class',
            'slug' => 'retired-class',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($user)
            ->get(route('crm.webinar-series.index'));

        $response
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('paidAdTrackingSetups', function (Collection $setups) use ($activeSeries, $inactiveSeries): bool {
                $activeSetup = $setups->get((string) $activeSeries->getKey());

                return is_array($activeSetup)
                    && $activeSetup['series_title'] === 'Homebuyer Class'
                    && $activeSetup['destination_url'] === route('webinar.show', [
                        'seriesSlug' => 'homebuyer-class',
                    ])
                    && str_contains(
                        $activeSetup['platforms']['meta']['parameters'],
                        'engage_campaign_id={{campaign.id}}',
                    )
                    && ! $setups->has((string) $inactiveSeries->getKey());
            })
            ->assertSee('data-ad-tracking-generator', false)
            ->assertSee('Ad Tracking Link Generator')
            ->assertSee('Ad tracking links');
    }

    public function test_webinar_workspace_hides_tracking_generator_when_reporting_is_disabled(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'webinars',
        ]);

        $user = User::factory()->create();
        WebinarSeries::factory()->create([
            'title' => 'Homebuyer Class',
            'slug' => 'homebuyer-class',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('paidAdTrackingSetups', fn (Collection $setups): bool => $setups->isEmpty())
            ->assertDontSee('data-ad-tracking-generator', false)
            ->assertDontSee('Ad tracking links');
    }
}