<?php

namespace Tests\Feature\PublicSurfaces;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPixelTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_pixel_is_not_rendered_when_client_configuration_is_disabled(): void
    {
        $this->activeWebinarSeries();

        config()->set('public_surfaces.tracking.meta_pixel', [
            'enabled' => false,
            'pixel_id' => '1586087269730616',
            'events' => [
                'webinar_registration_completed' => 'CompleteRegistration',
            ],
        ]);

        $this->get(route('webinar.index'))
            ->assertOk()
            ->assertDontSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertDontSee('1586087269730616');
    }

    public function test_meta_pixel_renders_pageview_on_shared_public_surfaces_when_enabled(): void
    {
        $this->activeWebinarSeries();

        config()->set('public_surfaces.tracking.meta_pixel', [
            'enabled' => true,
            'pixel_id' => '1586087269730616',
            'events' => [],
        ]);

        $this->get(route('webinar.index'))
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee('1586087269730616')
            ->assertSee("fbq('track', 'PageView');", false);
    }

    public function test_meta_pixel_fires_only_the_configured_event_for_a_flashed_public_conversion(): void
    {
        $this->activeWebinarSeries();

        config()->set('public_surfaces.tracking.meta_pixel', [
            'enabled' => true,
            'pixel_id' => '1586087269730616',
            'events' => [
                'webinar_registration_completed' => 'CompleteRegistration',
                'scheduling_booking_completed' => 'Schedule',
            ],
        ]);

        $this->withSession([
            'public_surfaces.tracking.event' => 'webinar_registration_completed',
        ])->get(route('webinar.index'))
            ->assertOk()
            ->assertSee('CompleteRegistration')
            ->assertDontSee('scheduling_booking_completed')
            ->assertDontSee('webinar_registration_completed');
    }

    public function test_unmapped_public_conversion_does_not_emit_an_arbitrary_meta_event(): void
    {
        $this->activeWebinarSeries();

        config()->set('public_surfaces.tracking.meta_pixel', [
            'enabled' => true,
            'pixel_id' => '1586087269730616',
            'events' => [
                'webinar_registration_completed' => 'CompleteRegistration',
            ],
        ]);

        $this->withSession([
            'public_surfaces.tracking.event' => 'unconfigured_event',
        ])->get(route('webinar.index'))
            ->assertOk()
            ->assertSee("fbq('track', 'PageView');", false)
            ->assertDontSee('unconfigured_event');
    }

    private function activeWebinarSeries(): WebinarSeries
    {
        return WebinarSeries::factory()->create([
            'status' => 'active',
            'title' => 'Tracking Test Webinar',
        ]);
    }
}