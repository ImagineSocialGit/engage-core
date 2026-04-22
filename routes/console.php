<?php

use App\Actions\Webinars\AdvanceWebinarSeriesStatusAction;
use App\Models\WebinarSeries;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (AdvanceWebinarSeriesStatusAction $action) {
    WebinarSeries::query()->each(function ($series) use ($action) {
        $action->execute($series);
    });
})->everyMinute();