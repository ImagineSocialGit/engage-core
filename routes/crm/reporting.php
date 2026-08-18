<?php

use App\Modules\Reporting\Controllers\CRM\ReportingController;
use App\Modules\Reporting\Controllers\CRM\ReportingExternalMeasurementImportController;
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

        Route::get('/imports/create', [ReportingExternalMeasurementImportController::class, 'create'])
            ->name('imports.create');

        Route::post('/imports/preview', [ReportingExternalMeasurementImportController::class, 'preview'])
            ->middleware('throttle:10,1')
            ->name('imports.preview');

        Route::post('/imports', [ReportingExternalMeasurementImportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('imports.store');
    });