<?php

use App\Http\Controllers\Public\WebinarJoinRedirectController;
use App\Http\Controllers\Public\WebinarRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebinarRegistrationController::class, 'index'])
    ->name('webinar.index');

Route::get('/j/{token}', WebinarJoinRedirectController::class)
    ->name('webinar.join.redirect');

Route::get('/{seriesSlug}', [WebinarRegistrationController::class, 'show'])
    ->name('webinar.show');

Route::post('/{seriesSlug}', [WebinarRegistrationController::class, 'store'])
    ->name('webinar.store');

Route::get('/{seriesSlug}/thank-you', function (string $seriesSlug) {
    return view('webinar.thank-you', [
        'seriesSlug' => $seriesSlug,
    ]);
})->name('webinar.thank_you');

Route::fallback(function () {
    abort(404);
});