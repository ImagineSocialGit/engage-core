<?php

use App\Modules\InboundMessaging\Controllers\Webhooks\EmailWebhookController;
use App\Modules\InboundMessaging\Controllers\Webhooks\SmsWebhookController;
use App\Modules\Webinars\Controllers\Webhooks\WebinarWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:webinars')->group(function () {
    Route::post('/webinar/{provider}', WebinarWebhookController::class)
        ->whereIn('provider', array_keys(config('webinars.providers', [])))
        ->name('webhooks.webinar');
});

Route::middleware('module:inbound_messaging')->group(function () {
    Route::post('/sms/{provider}', SmsWebhookController::class)
        ->whereIn('provider', ['telnyx'])
        ->name('webhooks.sms');

    Route::post('/email/{provider}', EmailWebhookController::class)
        ->whereIn('provider', ['resend'])
        ->name('webhooks.email');
});