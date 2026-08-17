<?php

use App\Modules\InboundMessaging\Controllers\Webhooks\EmailWebhookController;
use App\Modules\InboundMessaging\Controllers\Webhooks\SmsWebhookController;
use App\Modules\Forms\Controllers\External\ExternalFormIntakeController;
use App\Modules\Forms\Controllers\External\ExternalPublishedFormController;
use App\Modules\Forms\Http\Middleware\AuthenticateExternalFormIntake;
use App\Modules\Webinars\Controllers\Webhooks\WebinarWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/forms/{form}', ExternalPublishedFormController::class)
    ->middleware([
        'module:forms',
        AuthenticateExternalFormIntake::class,
    ])
    ->where('form', '[a-z][a-z0-9_]*')
    ->name('webhooks.forms.show');

Route::post('/forms/{form}/submissions', ExternalFormIntakeController::class)
    ->middleware([
        'module:forms',
        AuthenticateExternalFormIntake::class,
    ])
    ->where('form', '[a-z][a-z0-9_]*')
    ->name('webhooks.forms.submissions.store');

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