<?php

namespace Database\Factories;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebinarSeriesVariant>
 */
class WebinarSeriesVariantFactory extends Factory
{
    protected $model = WebinarSeriesVariant::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Central',
            'Eastern',
            'Mountain',
            'Pacific',
        ]).' '.fake()->unique()->numberBetween(10, 9999);

        return [
            'webinar_series_id' => WebinarSeries::factory(),
            'key' => Str::slug($name),
            'name' => $name,
            'public_slug' => Str::slug(fake()->unique()->sentence(4)),
            'timezone' => 'America/Chicago',
            'platform' => 'zoom',
            'provider_event_type' => 'meeting',
            'provider_match_title' => fake()->sentence(4),
            'status' => 'active',
            'is_default' => false,
            'meta' => [],
        ];
    }

    public function defaultVariant(): self
    {
        return $this->state(fn (): array => [
            'key' => 'primary',
            'is_default' => true,
        ]);
    }
}