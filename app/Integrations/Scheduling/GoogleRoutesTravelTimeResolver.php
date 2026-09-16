<?php

namespace App\Integrations\Scheduling;

use App\Modules\Scheduling\Contracts\TravelTimeResolver;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Data\TravelTimeEstimate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleRoutesTravelTimeResolver implements TravelTimeResolver
{
    public function estimate(
        SchedulingLocationSnapshot $origin,
        SchedulingLocationSnapshot $destination,
    ): TravelTimeEstimate {
        if (! $origin->isPhysical() || ! $destination->isPhysical()) {
            return new TravelTimeEstimate(0, 'non_physical');
        }

        $originAddress = $this->address($origin);
        $destinationAddress = $this->address($destination);

        if ($originAddress === $destinationAddress) {
            return new TravelTimeEstimate(0, 'same_address');
        }

        $apiKey = trim((string) config('scheduling.travel.google_routes.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('Google Routes API key is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(max(1, (int) config(
                    'scheduling.travel.google_routes.connect_timeout_seconds',
                    3,
                )))
                ->timeout(max(1, (int) config(
                    'scheduling.travel.google_routes.timeout_seconds',
                    5,
                )))
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'routes.duration',
                ])
                ->post(
                    (string) config(
                        'scheduling.travel.google_routes.endpoint',
                        'https://routes.googleapis.com/directions/v2:computeRoutes',
                    ),
                    [
                        'origin' => $this->waypoint($originAddress),
                        'destination' => $this->waypoint($destinationAddress),
                        'travelMode' => 'DRIVE',
                        'routingPreference' => (string) config(
                            'scheduling.travel.google_routes.routing_preference',
                            'TRAFFIC_AWARE',
                        ),
                        'computeAlternativeRoutes' => false,
                    ],
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Google Routes travel-time request could not connect.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Google Routes travel-time request failed with HTTP '.$response->status().'.',
            );
        }

        $duration = $response->json('routes.0.duration');

        if (! is_string($duration)
            || preg_match('/\\A(\\d+(?:\\.\\d+)?)s\\z/', trim($duration), $matches) !== 1
        ) {
            throw new RuntimeException(
                'Google Routes response did not contain a usable route duration.',
            );
        }

        $minutes = (int) ceil(((float) $matches[1]) / 60);
        $maximum = max(
            0,
            min(1440, (int) config('scheduling.travel.maximum_minutes', 240)),
        );

        return new TravelTimeEstimate(
            minutes: min($maximum, max(0, $minutes)),
            source: 'google_routes',
        );
    }

    /** @return array<string, mixed> */
    private function address(SchedulingLocationSnapshot $snapshot): array
    {
        $address = data_get($snapshot->details, 'address');

        if (! is_array($address)) {
            throw new RuntimeException(
                'Physical Scheduling locations require an address snapshot for route calculation.',
            );
        }

        return $address;
    }

    /** @param array<string, mixed> $address */
    private function waypoint(array $address): array
    {
        $latitude = $address['latitude'] ?? null;
        $longitude = $address['longitude'] ?? null;

        if (is_numeric($latitude) && is_numeric($longitude)) {
            return [
                'location' => [
                    'latLng' => [
                        'latitude' => (float) $latitude,
                        'longitude' => (float) $longitude,
                    ],
                ],
            ];
        }

        $formatted = is_string($address['formatted_address'] ?? null)
            ? trim($address['formatted_address'])
            : '';

        if ($formatted === '') {
            $formatted = implode(', ', array_values(array_filter([
                $address['address_line_1'] ?? null,
                $address['address_line_2'] ?? null,
                $address['city'] ?? null,
                trim(implode(' ', array_filter([
                    $address['region'] ?? null,
                    $address['postal_code'] ?? null,
                ]))),
                $address['country'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
        }

        if ($formatted === '') {
            throw new RuntimeException(
                'Physical Scheduling locations require a formatted address or coordinates for route calculation.',
            );
        }

        return ['address' => $formatted];
    }
}