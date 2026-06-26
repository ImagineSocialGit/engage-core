<?php

namespace Database\Seeders;

use App\Modules\Webinars\Models\WebinarSeries;
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

    }
}
