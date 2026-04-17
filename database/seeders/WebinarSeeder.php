<?php

namespace Database\Seeders;

use App\Models\Webinar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WebinarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Webinar::updateOrCreate(
            ['slug' => 'test-webinar'],
            [
                'title' => 'Staging Test Webinar',
                'status' => 'active',
                'platform' => 'zoom',
                'join_url' => 'https://example.com/join-test',
                'starts_at' => Carbon::now()->addMinutes(32),
                'ends_at' => Carbon::now()->addMinutes(92),
                'timezone' => 'America/Chicago',
                'description' => 'Seeded webinar for staging/testing.',
                'meta' => null,
            ]
        );
    }
}
