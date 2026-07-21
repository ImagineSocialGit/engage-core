<?php

namespace Database\Factories;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebinarSeries>
 */
class WebinarSeriesFactory extends Factory
{
    protected $model = WebinarSeries::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'status' => 'active',
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'meta' => [],
        ];
    }

    public function meeting(): self
    {
        return $this->state(fn (): array => [
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);
    }
}