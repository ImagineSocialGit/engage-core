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
            'slug' => 'va-homebuyer',
        ], [
            'title' => 'VA Homebuyer Class',
        ]);

        $relocationSeries = \App\Models\WebinarSeries::firstOrCreate([
            'slug' => 'relocation',
        ], [
            'title' => 'Relocation to Florida',
        ]);

        $investorSeries = \App\Models\WebinarSeries::firstOrCreate([
            'slug' => 'investor',
        ], [
            'title' => 'Investor/DSCR',
        ]);

        $constructionSeries = \App\Models\WebinarSeries::firstOrCreate([
            'slug' => 'construction',
        ], [
            'title' => 'Construction Loans',
        ]);

    }
}
