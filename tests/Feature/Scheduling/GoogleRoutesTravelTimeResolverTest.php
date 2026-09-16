<?php

namespace Tests\Feature\Scheduling;

use App\Integrations\Scheduling\GoogleRoutesTravelTimeResolver;
use App\Modules\Scheduling\Contracts\TravelTimeResolver;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Services\Availability\ConservativeTravelTimeResolver;
use App\Modules\Scheduling\Services\Availability\SchedulingTravelTimeResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleRoutesTravelTimeResolverTest extends TestCase
{
    public function test_google_routes_provider_returns_traffic_aware_drive_minutes(): void
    {
        config()->set('scheduling.travel.google_routes.api_key', 'test-key');
        config()->set('scheduling.travel.maximum_minutes', 240);
        Http::fake([
            'https://routes.googleapis.com/*' => Http::response([
                'routes' => [
                    ['duration' => '1001s'],
                ],
            ]),
        ]);

        $estimate = app(GoogleRoutesTravelTimeResolver::class)->estimate(
            $this->location('100 Main St, Denver, CO 80202'),
            $this->location('200 Market St, Denver, CO 80205'),
        );

        $this->assertSame(17, $estimate->minutes);
        $this->assertSame('google_routes', $estimate->source);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('X-Goog-Api-Key', 'test-key')
                && $request->hasHeader('X-Goog-FieldMask', 'routes.duration')
                && $request['travelMode'] === 'DRIVE'
                && $request['routingPreference'] === 'TRAFFIC_AWARE';
        });
    }

    public function test_scheduling_resolver_falls_back_when_selected_provider_fails(): void
    {
        config()->set('scheduling.travel.conservative_minutes', 45);
        $this->app->bind(
            TravelTimeResolver::class,
            fn () => new class implements TravelTimeResolver
            {
                public function estimate(
                    SchedulingLocationSnapshot $origin,
                    SchedulingLocationSnapshot $destination,
                ): \App\Modules\Scheduling\Data\TravelTimeEstimate {
                    throw new RuntimeException('provider unavailable');
                }
            },
        );

        $resolver = new SchedulingTravelTimeResolver(
            $this->app,
            app(ConservativeTravelTimeResolver::class),
        );
        $estimate = $resolver->estimate(
            $this->location('100 Main St, Denver, CO 80202'),
            $this->location('200 Market St, Denver, CO 80205'),
        );

        $this->assertSame(45, $estimate->minutes);
        $this->assertSame('conservative_fallback', $estimate->source);
    }

    private function location(string $formattedAddress): SchedulingLocationSnapshot
    {
        return SchedulingLocationSnapshot::fromNormalizedAddress(
            type: BookableService::LOCATION_TYPE_FIXED,
            address: [
                'address_line_1' => $formattedAddress,
                'city' => 'Denver',
                'region' => 'CO',
                'postal_code' => '80202',
                'country' => 'US',
                'formatted_address' => $formattedAddress,
            ],
        );
    }
}