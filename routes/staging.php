<?php

use App\Http\Controllers\StagingAccessController;
use Illuminate\Support\Facades\Route;

// These routes exist in every environment but return 404 outside staging.
Route::get('/staging-login', [StagingAccessController::class, 'show'])
    ->name('staging.login');

Route::post('/staging-login', [StagingAccessController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('staging.login.submit');

Route::post('/staging-logout', [StagingAccessController::class, 'destroy'])
    ->name('staging.logout');