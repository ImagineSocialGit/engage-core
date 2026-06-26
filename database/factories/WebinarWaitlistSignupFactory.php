<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
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
            'contact_id' => Contact::factory(),
            'webinar_series_id' => WebinarSeries::factory(),
            'notified_at' => null,
            'source_page' => 'webinar-notify-me',
            'meta' => [],
        ];
    }
}