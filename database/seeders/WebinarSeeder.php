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
            ['slug' => 'staging-webinar'],
            [
                'title' => 'Staging Webinar',
                'status' => 'active',
                'platform' => 'zoom',
                'join_url' => 'https://example.com/join-test',
                'external_id' => '89453666282',
                'starts_at' => Carbon::create(2026, 4, 20, 13, 15, 0, 'America/Chicago')->utc(),
                'ends_at' => Carbon::create(2026, 4, 20, 13, 30, 0, 'America/Chicago')->utc(),
                'timezone' => 'America/Chicago',
                'description' => 'Seeded webinar for staging/testing.',
                'meta' => null,
            ]
        );
    }
}
