<?php

use App\Http\Controllers\Public\WebinarJoinRedirectController;
use App\Http\Controllers\Public\WebinarRegistrationController;
use App\Http\Controllers\Webhooks\WebinarWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebinarRegistrationController::class, 'index'])
    ->name('webinar.index');

Route::post('/webhooks/zoom', WebinarWebhookController::class)
    ->name('webhooks.zoom');

Route::get('/j/{token}', WebinarJoinRedirectController::class)
    ->name('webinar.join.redirect');

Route::pattern('seriesSlug', '[a-z0-9-]+');

Route::get('/{seriesSlug}', [WebinarRegistrationController::class, 'show'])
    ->name('webinar.show');

Route::post('/{seriesSlug}/notify-me', [WebinarRegistrationController::class, 'storeNotificationSignup'])
    ->middleware('throttle:webinar-registration')
    ->name('webinar.notify-me.store');

Route::post('/{seriesSlug}', [WebinarRegistrationController::class, 'store'])
    ->middleware('throttle:webinar-registration')
    ->name('webinar.registration.store');

Route::get('/{seriesSlug}/thank-you', [WebinarRegistrationController::class, 'showThankYou'])
    ->name('webinar.thank-you');

Route::fallback(function () {
    abort(404);
});