<?php

namespace Database\Seeders;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use Illuminate\Database\Seeder;

class WebinarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $devSeries = WebinarSeries::firstOrCreate([
            'slug' => 'dev-webinar',
        ], [
            'title' => 'Dev Webinar',
        ]);

        WebinarSeriesVariant::query()->firstOrCreate([
            'webinar_series_id' => $devSeries->getKey(),
            'key' => 'default',
        ], [
            'name' => 'Central',
            'public_slug' => $devSeries->slug,
            'timezone' => 'America/Chicago',
            'platform' => $devSeries->providerKey(),
            'provider_event_type' => $devSeries->providerEventTypeKey(),
            'provider_match_title' => $devSeries->title,
            'status' => 'active',
            'is_default' => true,
            'meta' => [
                'compatibility' => [
                    'preserves_series_public_slug' => true,
                ],
            ],
        ]);
    }
}