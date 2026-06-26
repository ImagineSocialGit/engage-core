<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WebinarRegistrationFactory extends Factory
{
    protected $model = WebinarRegistration::class;

    public function definition(): array
    {
        return [

            'contact_id' => Contact::factory(),
            'webinar_id' => Webinar::factory(),
            'join_token' => Str::uuid(),
            'webinar_slug' => $this->faker->slug(),
            'status' => 'pending',
            'source' => 'webinar_subdomain',
            'meta' => [],
            'registered_at' => now(),
            'attended_at' => null,
        ];
    }
}