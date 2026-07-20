<?php

use App\Http\Controllers\CRM\DashboardController;
use App\Modules\Broadcasts\Controllers\BroadcastController;
use App\Modules\Campaigns\Controllers\CRM\CampaignMessageTemplateController;
use App\Modules\Core\Controllers\ContactController;
use App\Modules\Core\Controllers\ContactImportBatchController;
use App\Modules\Core\Controllers\ContactLookupController;
use App\Modules\Core\Controllers\ContactNoteController;
use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteBindingController;
use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteController;
use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteEditorController;
use App\Modules\Messaging\Controllers\ContactImportBatchPermissionInvitationController;
use App\Modules\Messaging\Controllers\CRM\MessageTemplatePresetController;
use App\Modules\Tasks\Controllers\TaskController;
use App\Modules\Webinars\Controllers\CRM\WebinarController;
use App\Modules\Webinars\Controllers\CRM\WebinarDevController;
use App\Modules\Webinars\Controllers\CRM\WebinarMessageTemplateController;
use App\Modules\Webinars\Controllers\CRM\WebinarProviderCancellationController;
use App\Modules\Webinars\Controllers\CRM\WebinarRegistrationFinalizationController;
use App\Modules\Webinars\Controllers\CRM\WebinarRegistrationFollowUpController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('crm.index');

    Route::post('/dashboard/acknowledgements', [DashboardController::class, 'acknowledge'])
        ->name('crm.dashboard.acknowledgements.store');

    Route::middleware('module:webinars')->group(function () {
        Route::get('/webinars', [WebinarController::class, 'index'])
            ->name('crm.webinar-series.index');

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

        Route::patch('/webinar-series/{series}/schedule-profile', [WebinarController::class, 'updateSeriesScheduleProfile'])
            ->name('crm.webinar-series.schedule-profile.update');

        Route::delete('/webinar-series/{series}', [WebinarController::class, 'destroySeries'])
            ->name('crm.webinar-series.destroy');

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

    Route::middleware(['module:campaigns', 'module:messaging'])
        ->prefix('campaigns/message-templates')
        ->name('crm.campaigns.message-templates.')
        ->group(function () {
            Route::get('/', [CampaignMessageTemplateController::class, 'index'])
                ->name('index');

            Route::patch('/steps/{campaignStep}', [CampaignMessageTemplateController::class, 'update'])
                ->name('update');
        });

    Route::middleware('module:messaging')
        ->prefix('message-templates')
        ->name('crm.messaging.message-templates.')
        ->group(function () {
            Route::get('/', [MessageTemplatePresetController::class, 'index'])
                ->name('index');

            Route::patch('/{messageTemplatePreset}', [MessageTemplatePresetController::class, 'update'])
                ->name('update');
        });

    Route::middleware('module:flow_routes')
        ->prefix('flow-routes')
        ->name('crm.flow-routes.')
        ->group(function () {
            Route::get('/', [FlowRouteController::class, 'index'])
                ->name('index');

            Route::get('/bindings', [FlowRouteBindingController::class, 'index'])
                ->name('bindings.index');

            Route::patch('/bindings', [FlowRouteBindingController::class, 'update'])
                ->name('bindings.update');

            Route::get('/{flowRoute}', [FlowRouteEditorController::class, 'show'])
                ->name('show');

            Route::post('/{flowRoute}/points', [FlowRouteEditorController::class, 'storePoint'])
                ->name('points.store');

            Route::patch('/{flowRoute}/points/order', [FlowRouteEditorController::class, 'reorderPoints'])
                ->name('points.order');

            Route::patch('/{flowRoute}/points/{flowRoutePoint}', [FlowRouteEditorController::class, 'updatePoint'])
                ->name('points.update');

            Route::delete('/{flowRoute}/points/{flowRoutePoint}', [FlowRouteEditorController::class, 'destroyPoint'])
                ->name('points.destroy');

            Route::patch('/{flowRoute}/points/{flowRoutePoint}/move-up', [FlowRouteEditorController::class, 'movePointUp'])
                ->name('points.move-up');

            Route::patch('/{flowRoute}/points/{flowRoutePoint}/move-down', [FlowRouteEditorController::class, 'movePointDown'])
                ->name('points.move-down');
        });

    Route::middleware('module:broadcasts')
        ->prefix('broadcasts')
        ->name('crm.broadcasts.')
        ->group(function () {
            Route::get('/', [BroadcastController::class, 'index'])
                ->name('index');

            Route::post('/', [BroadcastController::class, 'store'])
                ->name('store');

            Route::get('/{broadcast}', [BroadcastController::class, 'show'])
                ->name('show');

            Route::get('/{broadcast}/edit', [BroadcastController::class, 'edit'])
                ->name('edit');

            Route::patch('/{broadcast}', [BroadcastController::class, 'update'])
                ->name('update');

            Route::patch('/{broadcast}/schedule', [BroadcastController::class, 'schedule'])
                ->name('schedule');

            Route::patch('/{broadcast}/cancel', [BroadcastController::class, 'cancel'])
                ->name('cancel');
        });

    Route::middleware('module:tasks')
        ->prefix('tasks')
        ->name('crm.tasks.')
        ->group(function () {
            Route::get('/', [TaskController::class, 'index'])
                ->name('index');

            Route::get('/today/print', [DashboardController::class, 'printTasks'])
                ->name('today.print');

            Route::post('/today/broadcast', [DashboardController::class, 'broadcastTasks'])
                ->name('today.broadcast');

            Route::post('/', [TaskController::class, 'store'])
                ->name('store');

            Route::get('/{task}', [TaskController::class, 'show'])
                ->name('show');

            Route::patch('/{task}/complete', [TaskController::class, 'complete'])
                ->name('complete');

            Route::patch('/{task}/cancel', [TaskController::class, 'cancel'])
                ->name('cancel');

            Route::patch('/{task}/reopen', [TaskController::class, 'reopen'])
                ->name('reopen');

            Route::patch('/{task}/archive', [TaskController::class, 'archive'])
                ->name('archive');

            Route::patch('/{task}/restore', [TaskController::class, 'restore'])
                ->name('restore');
        });

    Route::prefix(config('contacts.routes.plural'))
        ->name('crm.contacts.')
        ->group(function () {
            Route::get('/', [ContactController::class, 'index'])
                ->name('index');

            Route::post('/', [ContactController::class, 'store'])
                ->name('store');

            Route::get('/lookup', ContactLookupController::class)
                ->name('lookup');

            Route::get('/import', [ContactController::class, 'import'])
                ->name('import');

            Route::post('/import/preview', [ContactController::class, 'previewImport'])
                ->name('import.preview');

            Route::post('/import', [ContactController::class, 'processImport'])
                ->name('import.process');

            Route::get('/import-batches', [ContactImportBatchController::class, 'index'])
                ->name('import-batches.index');

            Route::get('/import-batches/{contactImportBatch}', [ContactImportBatchController::class, 'show'])
                ->name('import-batches.show');

            Route::post('/import-batches/{contactImportBatch}/permission-invitations', ContactImportBatchPermissionInvitationController::class)
                ->middleware('module:messaging')
                ->name('import-batches.permission-invitations.store');

            Route::delete('/import-batches/{contactImportBatch}/permission-invitations', [ContactImportBatchPermissionInvitationController::class, 'destroy'])
                ->middleware('module:messaging')
                ->name('import-batches.permission-invitations.destroy');

            Route::get('/{contact}', [ContactController::class, 'show'])
                ->name('show');

            Route::patch('/{contact}/status', [ContactController::class, 'updateStatus'])
                ->middleware('module:workflow')
                ->name('status.update');

            Route::post('/{contact}/notes', [ContactNoteController::class, 'store'])
                ->name('notes.store');

            Route::patch('/{contact}/notes/{note}', [ContactNoteController::class, 'update'])
                ->name('notes.update');

            Route::delete('/{contact}/notes/{note}', [ContactNoteController::class, 'destroy'])
                ->name('notes.destroy');
        });
});