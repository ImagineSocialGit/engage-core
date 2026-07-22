<?php

use App\Modules\Scheduling\Controllers\PublicBookingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicBookingController::class, 'index'])
    ->name('scheduling.public.index');

Route::get('/services/{serviceKey}', [PublicBookingController::class, 'show'])
    ->where('serviceKey', '[A-Za-z0-9][A-Za-z0-9_-]*')
    ->name('scheduling.public.services.show');