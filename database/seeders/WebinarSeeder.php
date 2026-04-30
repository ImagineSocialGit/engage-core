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

        $homebuyerSeries = \App\Models\WebinarSeries::firstOrCreate([
            'slug' => 'homebuyer-game-plan',
        ], [
            'title' => 'Homebuyer Game Plan',
        ]);

        $vaSeries = \App\Models\WebinarSeries::firstOrCreate([
            'slug' => 'va-homebuyer-game-plan',
        ], [
            'title' => 'VA Homebuyer Game Plan',
        ]);

    }
}
