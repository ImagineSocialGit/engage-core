<?php

use App\Modules\Messaging\Controllers\Public\ConsentRevocationController;
use Illuminate\Support\Facades\Route;

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