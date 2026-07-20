<?php

use App\Modules\Messaging\Controllers\Public\ConsentRevocationController;
use App\Modules\Webinars\Controllers\Public\WebinarJoinRedirectController;
use App\Modules\Webinars\Controllers\Public\WebinarPlaybackRedirectController;
use App\Modules\Webinars\Controllers\Public\WebinarRegistrationCancellationController;
use App\Modules\Webinars\Controllers\Public\WebinarRegistrationController;
use App\Modules\Webinars\Controllers\Public\WebinarWaitlistSignupController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:webinars')->group(function () {
    Route::get('/', [WebinarRegistrationController::class, 'index'])
        ->name('webinar.index');

    Route::get('/j/{token}', WebinarJoinRedirectController::class)
        ->name('webinar.join.redirect');

    Route::get('/p/{token}', WebinarPlaybackRedirectController::class)
        ->name('webinar.playback.redirect');

    Route::get('/registrations/{registration}/cancel', [WebinarRegistrationCancellationController::class, 'show'])
        ->middleware(['signed:relative', 'throttle:6,1'])
        ->name('webinar.registration.cancellation.show');

    Route::post('/registrations/{registration}/cancel', [WebinarRegistrationCancellationController::class, 'store'])
        ->middleware(['signed:relative', 'throttle:6,1'])
        ->name('webinar.registration.cancellation.store');

    Route::pattern('seriesSlug', '(?!staging-login$)[a-z0-9-]+');

    Route::get('/{seriesSlug}/waitlist/{signup}/register', [WebinarRegistrationController::class, 'showFromWaitlist'])
        ->middleware(['signed:relative', 'throttle:12,1'])
        ->whereNumber('signup')
        ->name('webinar.waitlist.register');

    Route::get('/{seriesSlug}', [WebinarRegistrationController::class, 'show'])
        ->name('webinar.show');

    Route::post('/{seriesSlug}/waitlist', WebinarWaitlistSignupController::class)
        ->middleware('throttle:webinar-waitlist')
        ->name('webinar.waitlist.store');

    Route::post('/{seriesSlug}', [WebinarRegistrationController::class, 'store'])
        ->middleware(['signed:relative', 'throttle:webinar-registration'])
        ->name('webinar.registration.store');

    Route::get('/{seriesSlug}/thank-you/{registration}', [WebinarRegistrationController::class, 'showThankYou'])
        ->middleware('signed:relative')
        ->whereNumber('registration')
        ->name('webinar.thank-you');
});

Route::middleware('module:messaging')->group(function () {
    Route::get(
        '/unsubscribe/{contact}',
        [ConsentRevocationController::class, 'emailMarketingUnsubscribe']
    )
        ->middleware('throttle:6,1')
        ->name('messaging.email.unsubscribe');

    Route::post(
        '/unsubscribe/{contact}',
        [ConsentRevocationController::class, 'storeEmailMarketingUnsubscribe']
    )
        ->middleware('throttle:6,1')
        ->name('messaging.email.unsubscribe.store');

    Route::get(
        '/email-preferences/transactional/opt-out/{contact}',
        [ConsentRevocationController::class, 'emailTransactionalOptOut']
    )
        ->middleware('throttle:6,1')
        ->name('messaging.email.transactional-opt-out');

    Route::post(
        '/email-preferences/transactional/opt-out/{contact}',
        [ConsentRevocationController::class, 'storeEmailTransactionalOptOut']
    )
        ->middleware('throttle:6,1')
        ->name('messaging.email.transactional-opt-out.store');
});

Route::fallback(function () {
    abort(404);
});