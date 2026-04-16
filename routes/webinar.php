<?php

use App\Http\Controllers\Public\WebinarRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/{webinar:slug}', [WebinarRegistrationController::class, 'show']);
Route::post('/{webinar:slug}/register', [WebinarRegistrationController::class, 'store']);
Route::view('/{webinar:slug}/thank-you', 'webinar.thank-you');

Route::fallback(function () {
    abort(404);
});