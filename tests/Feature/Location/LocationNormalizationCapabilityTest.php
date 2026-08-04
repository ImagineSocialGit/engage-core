<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Actions\NormalizeLocationInputAction;
use App\Modules\Location\Contracts\LocationNormalizationProvider;
use App\Modules\Location\Data\LocationNormalizationInput;
use App\Modules\Location\Data\NormalizedLocationData;
use App\Modules\Location\Exceptions\LocationNormalizationException;
use App\Modules\Location\Models\ContactLocation;
use App\Modules\Location\Models\Location;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use App\Modules\Location\Providers\LocationModuleServiceProvider;
use App\Modules\Location\Services\DeterministicLocationNormalizationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class LocationNormalizationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_provider_is_the_deterministic_normalizer(): void
    {
        $this->enableLocation();

        $this->assertInstanceOf(
            DeterministicLocationNormalizationProvider::class,
            app(LocationNormalizationProvider::class),
        );
    }

    public function test_action_normalizes_closed_address_input_without_persistence_or_fake_enrichment(): void
    {
        $this->enableLocation();

        $result = app(NormalizeLocationInputAction::class)->handle([
            'address_line_1' => '  123   Main Street  ',
            'address_line_2' => ' Suite   200 ',
            'city' => '  Melbourne ',
            'region' => ' FL ',
            'postal_code' => ' 32901 ',
            'country' => ' us ',
        ]);

        $this->assertSame('123 Main Street', $result->addressLine1);
        $this->assertSame('Suite 200', $result->addressLine2);
        $this->assertSame('Melbourne', $result->city);
        $this->assertSame('FL', $result->region);
        $this->assertSame('32901', $result->postalCode);
        $this->assertSame('US', $result->country);
        $this->assertSame(
            '123 Main Street, Suite 200, Melbourne, FL 32901, US',
            $result->formattedAddress,
        );
        $this->assertFalse($result->hasCoordinates());
        $this->assertNull($result->timezone);
        $this->assertNull($result->precision);
        $this->assertNull($result->confidence);
        $this->assertNull($result->provider);

        $this->assertSame(0, Location::query()->count());
        $this->assertSame(0, ContactLocation::query()->count());
        $this->assertSame(0, LocationArea::query()->count());
        $this->assertSame(0, LocationAreaAssignment::query()->count());
    }

    public function test_input_rejects_unknown_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported location normalization input field(s): [latitude].',
        );

        LocationNormalizationInput::fromArray([
            ...$this->validInput(),
            'latitude' => 28.0836,
        ]);
    }

    public function test_input_requires_every_authoritative_address_field(): void
    {
        $input = $this->validInput();
        unset($input['postal_code']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Location normalization field [postal_code] is required.',
        );

        LocationNormalizationInput::fromArray($input);
    }

    public function test_input_requires_a_two_letter_country_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Location normalization field [country] must be a two-letter country code.',
        );

        LocationNormalizationInput::fromArray([
            ...$this->validInput(),
            'country' => 'United States',
        ]);
    }

    public function test_configured_provider_may_return_provider_neutral_enrichment(): void
    {
        $this->enableLocation(EnrichedLocationNormalizationProvider::class);

        $result = app(NormalizeLocationInputAction::class)->handle(
            $this->validInput(),
        );

        $this->assertSame('123 Main St', $result->addressLine1);
        $this->assertSame(28.0836, $result->latitude);
        $this->assertSame(-80.6081, $result->longitude);
        $this->assertSame('America/New_York', $result->timezone);
        $this->assertSame('address', $result->precision);
        $this->assertSame(0.98, $result->confidence);
        $this->assertSame('test_provider', $result->provider);
        $this->assertTrue($result->hasCoordinates());

        $this->assertEquals([
            'address_line_1',
            'address_line_2',
            'city',
            'region',
            'postal_code',
            'country',
            'formatted_address',
            'latitude',
            'longitude',
            'timezone',
            'precision',
            'confidence',
            'provider',
        ], array_keys($result->toArray()));
        $this->assertArrayNotHasKey('raw_payload', $result->toArray());
        $this->assertArrayNotHasKey('external_id', $result->toArray());
    }

    public function test_provider_failures_are_exposed_as_location_normalization_failures(): void
    {
        $this->enableLocation(FailingLocationNormalizationProvider::class);

        try {
            app(NormalizeLocationInputAction::class)->handle(
                $this->validInput(),
            );

            $this->fail('Expected LocationNormalizationException was not thrown.');
        } catch (LocationNormalizationException $exception) {
            $this->assertStringContainsString(
                FailingLocationNormalizationProvider::class,
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                'provider unavailable',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_configured_provider_must_implement_the_public_contract(): void
    {
        $this->enableLocation(InvalidLocationNormalizationProvider::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must implement ['.LocationNormalizationProvider::class.']',
        );

        app(LocationNormalizationProvider::class);
    }

    private function enableLocation(?string $providerClass = null): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'location',
        ])));

        if ($providerClass !== null) {
            config()->set('location.normalization.provider', $providerClass);
        }

        if (! $this->app->getProvider(LocationModuleServiceProvider::class)) {
            $this->app->register(LocationModuleServiceProvider::class);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function validInput(): array
    {
        return [
            'address_line_1' => '123 Main Street',
            'address_line_2' => null,
            'city' => 'Melbourne',
            'region' => 'FL',
            'postal_code' => '32901',
            'country' => 'US',
        ];
    }
}

final class EnrichedLocationNormalizationProvider implements LocationNormalizationProvider
{
    public function normalize(
        LocationNormalizationInput $input,
    ): NormalizedLocationData {
        return new NormalizedLocationData(
            addressLine1: '123 Main St',
            addressLine2: null,
            city: 'Melbourne',
            region: 'FL',
            postalCode: '32901',
            country: 'US',
            formattedAddress: '123 Main St, Melbourne, FL 32901, US',
            latitude: 28.0836,
            longitude: -80.6081,
            timezone: 'America/New_York',
            precision: 'address',
            confidence: 0.98,
            provider: 'test_provider',
        );
    }
}

final class FailingLocationNormalizationProvider implements LocationNormalizationProvider
{
    public function normalize(
        LocationNormalizationInput $input,
    ): NormalizedLocationData {
        throw new RuntimeException('provider unavailable');
    }
}

final class InvalidLocationNormalizationProvider
{
    // Intentionally does not implement LocationNormalizationProvider.
}