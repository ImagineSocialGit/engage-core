<?php

use App\Http\Controllers\Webhooks\TwilioSmsWebhookController;
use App\Http\Controllers\Webhooks\WebinarWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/zoom', WebinarWebhookController::class)
    ->name('webhooks.zoom');

Route::post('/webhooks/twilio/sms', TwilioSmsWebhookController::class)
    ->name('webhooks.twilio.sms');