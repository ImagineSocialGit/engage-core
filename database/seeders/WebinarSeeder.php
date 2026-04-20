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
                'title' => 'Test Webinar',
                'status' => 'active',
                'platform' => 'zoom',
                'join_url' => 'https://example.com/join-test',
                'external_id' => '86488123410',
                'starts_at' => Carbon::create(2026, 4, 20, 12, 30, 0, 'America/Chicago'),
                'ends_at' => Carbon::create(2026, 4, 20, 12, 45, 0, 'America/Chicago'),
                'timezone' => 'America/Chicago',
                'description' => 'Seeded webinar for staging/testing.',
                'meta' => null,
            ]
        );
    }
}
