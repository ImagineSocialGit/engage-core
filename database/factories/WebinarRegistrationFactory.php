<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
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