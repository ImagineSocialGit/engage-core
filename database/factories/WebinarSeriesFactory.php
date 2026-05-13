<?php

namespace Database\Factories;

use App\Models\WebinarSeries;
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
            'meta' => [],
        ];
    }
}