<?php

use App\Modules\Webinars\Controllers\CRM\WebinarController;
use App\Modules\Webinars\Controllers\CRM\WebinarDevController;
use App\Modules\Webinars\Controllers\CRM\WebinarMessageTemplateController;
use App\Modules\Webinars\Controllers\CRM\WebinarPostEventReviewController;
use App\Modules\Webinars\Controllers\CRM\WebinarProviderCancellationController;
use App\Modules\Webinars\Controllers\CRM\WebinarRegistrationFinalizationController;
use App\Modules\Webinars\Controllers\CRM\WebinarRegistrationFollowUpController;
use App\Modules\Webinars\Controllers\CRM\WebinarSeriesMessageChainController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:webinars')->group(function () {
    Route::get('/webinars', [WebinarController::class, 'index'])
        ->name('crm.webinar-series.index');

    Route::get('/webinar-series/{series}', [WebinarController::class, 'showSeries'])
        ->name('crm.webinar-series.show');

    Route::get('/webinars/{webinar}/post-event-review', [WebinarPostEventReviewController::class, 'show'])
        ->name('crm.webinars.post-event-review.show');

    Route::patch('/webinars/{webinar}/post-event-review', [WebinarPostEventReviewController::class, 'update'])
        ->name('crm.webinars.post-event-review.update');

    Route::get('/webinars/{webinar}', [WebinarController::class, 'showWebinar'])
        ->whereNumber('webinar')
        ->name('crm.webinars.show');

    Route::middleware('module:messaging')
        ->prefix('webinars/message-templates')
        ->name('crm.webinars.message-templates.')
        ->group(function () {
            Route::get('/', [WebinarMessageTemplateController::class, 'index'])
                ->name('index');

            Route::patch('/', [WebinarMessageTemplateController::class, 'update'])
                ->name('update');
        });

    Route::post('/webinar-registrations/{registration}/provider-cancellation/retry', WebinarProviderCancellationController::class)
        ->name('crm.webinar-registrations.provider-cancellation.retry');

    Route::post('/webinar-registrations/{registration}/follow-up/retry', WebinarRegistrationFollowUpController::class)
        ->name('crm.webinar-registrations.follow-up.retry');

    Route::post('/webinar-registrations/{registration}/finalization/retry', [WebinarRegistrationFinalizationController::class, 'retry'])
        ->name('crm.webinar-registrations.finalization.retry');

    Route::post('/webinar-registrations/{registration}/finalization/reconcile', [WebinarRegistrationFinalizationController::class, 'reconcile'])
        ->name('crm.webinar-registrations.finalization.reconcile');

    Route::post('/webinar-series', [WebinarController::class, 'storeSeries'])
        ->name('crm.webinar-series.store');

    Route::post('/webinar-series/sync', [WebinarController::class, 'syncSeries'])
        ->name('crm.webinar-series.sync');

    Route::post('/webinar-series/{series}/fix-active', [WebinarController::class, 'fixActive'])
        ->name('crm.webinar-series.fix-active');

    Route::patch('/webinar-series/{series}/provider-event-type', [WebinarController::class, 'updateSeriesProviderEventType'])
        ->name('crm.webinar-series.provider-event-type.update');

    Route::patch('/webinar-series/{series}/schedule-profile', [WebinarController::class, 'updateSeriesScheduleProfile'])
        ->name('crm.webinar-series.schedule-profile.update');

    Route::patch('/webinars/{webinar}/schedule-profile', [WebinarController::class, 'updateWebinarScheduleProfile'])
        ->name('crm.webinars.schedule-profile.update');

    Route::middleware('module:messaging')
        ->prefix('webinar-series/{series}/message-chains')
        ->name('crm.webinar-series.message-chains.')
        ->group(function () {
            Route::get('/', [WebinarSeriesMessageChainController::class, 'show'])
                ->name('show');

            Route::post('/duplicate', [WebinarSeriesMessageChainController::class, 'duplicate'])
                ->name('duplicate');

            Route::patch('/variants/{variant}', [WebinarSeriesMessageChainController::class, 'updateVariant'])
                ->name('variants.update');
        });

    Route::post('/webinars/{webinar}/replacement', [WebinarController::class, 'replaceOccurrence'])
        ->name('crm.webinars.replacements.store');

    Route::delete('/webinars/{webinar}', [WebinarController::class, 'removeOccurrence'])
        ->name('crm.webinars.destroy');

    Route::patch('/webinars/{webinar}/restore', [WebinarController::class, 'restoreOccurrence'])
        ->name('crm.webinars.restore');

    Route::patch('/webinar-occurrence-suppressions/{suppression}/restore', [WebinarController::class, 'restoreSuppressedOccurrence'])
        ->name('crm.webinar-occurrence-suppressions.restore');

    Route::delete('/webinar-series/{series}', [WebinarController::class, 'destroySeries'])
        ->name('crm.webinar-series.destroy');

    Route::patch('/webinar-series/{series}/restore', [WebinarController::class, 'restoreSeries'])
        ->name('crm.webinar-series.restore');

    Route::get('/webinar-registrations/{registration}/dev/message-options', [WebinarDevController::class, 'messageOptions'])
        ->name('crm.webinar-registrations.dev.message-options.index');

    Route::post('/webinar-registrations/{registration}/dev/messages', [WebinarDevController::class, 'sendRegistrationMessageNow'])
        ->name('crm.webinar-registrations.dev.messages.store');

    Route::post('/webinar-registrations/{registration}/dev/messages/all', [WebinarDevController::class, 'sendAllRegistrationMessagesNow'])
        ->name('crm.webinar-registrations.dev.messages.all.store');

    Route::post('/webinar-registrations/{registration}/dev/join', [WebinarDevController::class, 'simulateJoin'])
        ->name('crm.webinar-registrations.dev.join.store');

    Route::post('/webinars/{webinar}/dev/replay-url', [WebinarDevController::class, 'setReplayUrl'])
        ->name('crm.webinars.dev.replay-url.store');

    Route::delete('/webinars/{webinar}/dev/replay-url', [WebinarDevController::class, 'clearReplayUrl'])
        ->name('crm.webinars.dev.replay-url.destroy');

    Route::post('/webinars/{webinar}/dev/follow-ups', [WebinarDevController::class, 'dispatchFollowUps'])
        ->name('crm.webinars.dev.follow-ups.store');

    Route::post('/webinar-registrations/{registration}/dev/attended', [WebinarDevController::class, 'markRegistrationAttended'])
        ->name('crm.webinar-registrations.dev.attended.store');

    Route::post('/webinar-registrations/{registration}/dev/missed', [WebinarDevController::class, 'markRegistrationMissed'])
        ->name('crm.webinar-registrations.dev.missed.store');

    Route::post('/webinar-registrations/{registration}/dev/reset', [WebinarDevController::class, 'resetRegistration'])
        ->name('crm.webinar-registrations.dev.reset.store');

    Route::post('/webinars/{webinar}/smoke/replay-url', [WebinarDevController::class, 'setReplayUrl'])
        ->name('crm.webinars.smoke.replay-url.store');

    Route::delete('/webinars/{webinar}/smoke/replay-url', [WebinarDevController::class, 'clearReplayUrl'])
        ->name('crm.webinars.smoke.replay-url.destroy');

    Route::post('/webinars/{webinar}/smoke/follow-ups', [WebinarDevController::class, 'dispatchFollowUps'])
        ->name('crm.webinars.smoke.follow-ups.store');

    Route::post('/webinar-registrations/{registration}/smoke/attended', [WebinarDevController::class, 'markRegistrationAttended'])
        ->name('crm.webinar-registrations.smoke.attended.store');

    Route::post('/webinar-registrations/{registration}/smoke/missed', [WebinarDevController::class, 'markRegistrationMissed'])
        ->name('crm.webinar-registrations.smoke.missed.store');

    Route::post('/webinar-registrations/{registration}/smoke/reset', [WebinarDevController::class, 'resetRegistration'])
        ->name('crm.webinar-registrations.smoke.reset.store');
});