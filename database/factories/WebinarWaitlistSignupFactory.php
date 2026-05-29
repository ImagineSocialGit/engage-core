<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebinarWaitlistSignup>
 */
class WebinarWaitlistSignupFactory extends Factory
{
    protected $model = WebinarWaitlistSignup::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'webinar_series_id' => WebinarSeries::factory(),
            'notified_at' => null,
            'source_page' => 'webinar-notify-me',
            'meta' => [],
        ];
    }
}