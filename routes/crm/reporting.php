<?php

use App\Modules\Reporting\Controllers\CRM\ReportingController;
use App\Modules\Reporting\Controllers\CRM\ReportingExternalMeasurementImportController;
use App\Modules\Reporting\Controllers\CRM\ScheduledReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:reporting')
    ->prefix('reporting')
    ->name('crm.reporting.')
    ->group(function () {
        Route::get('/', [ReportingController::class, 'index'])
            ->name('index');

        Route::post('/refresh', [ReportingController::class, 'refresh'])
            ->middleware('throttle:6,1')
            ->name('refresh');

        Route::get('/scheduled-reports', [ScheduledReportController::class, 'index'])
            ->name('scheduled-reports.index');

        Route::post('/scheduled-reports', [ScheduledReportController::class, 'store'])
            ->name('scheduled-reports.store');

        Route::patch('/scheduled-reports/{scheduledReportSubscription}', [ScheduledReportController::class, 'update'])
            ->name('scheduled-reports.update');

        Route::delete('/scheduled-reports/{scheduledReportSubscription}', [ScheduledReportController::class, 'destroy'])
            ->name('scheduled-reports.destroy');

        Route::post('/scheduled-reports/{scheduledReportSubscription}/send-now', [ScheduledReportController::class, 'sendNow'])
            ->middleware('throttle:6,1')
            ->name('scheduled-reports.send-now');

        Route::get('/imports/create', [ReportingExternalMeasurementImportController::class, 'create'])
            ->name('imports.create');

        Route::post('/imports/preview', [ReportingExternalMeasurementImportController::class, 'preview'])
            ->middleware('throttle:10,1')
            ->name('imports.preview');

        Route::post('/imports', [ReportingExternalMeasurementImportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('imports.store');
    });