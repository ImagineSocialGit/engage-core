<?php

namespace Database\Factories;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webinar>
 */
class WebinarFactory extends Factory
{
    protected $model = Webinar::class;

    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'webinar_series_id' => WebinarSeries::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'host_account_key' => 'default',
            'platform' => 'zoom',
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->paragraph(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
            'timezone' => 'America/Chicago',
            'join_url' => fake()->url(),
            'registration_url' => fake()->url(),
            'meta' => [],
        ];
    }
}