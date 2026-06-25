<?php

use App\Http\Controllers\CRM\ContactController;
use App\Http\Controllers\CRM\ContactNoteController;
use App\Http\Controllers\CRM\TaskController;
use App\Http\Controllers\CRM\WebinarController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [ContactController::class, 'index'])->name('crm.index');

    Route::middleware('module:webinars')->group(function () {
        Route::get('/webinars', [WebinarController::class, 'index'])
            ->name('crm.webinar-series.index');

        Route::post('/webinar-series', [WebinarController::class, 'storeSeries'])
            ->name('crm.webinar-series.store');

        Route::post('/webinar-series/sync', [WebinarController::class, 'syncSeries'])
            ->name('crm.webinar-series.sync');

        Route::post('/webinar-series/{series}/fix-active', [WebinarController::class, 'fixActive'])
            ->name('crm.webinar-series.fix-active');

        Route::delete('/webinar-series/{series}', [WebinarController::class, 'destroySeries'])
            ->name('crm.webinar-series.destroy');
    });

    Route::middleware('module:tasks')
        ->prefix('tasks')
        ->name('crm.tasks.')
        ->group(function () {
            Route::post('/', [TaskController::class, 'store'])
                ->name('store');

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

            Route::get('/import', [ContactController::class, 'import'])
                ->name('import');

            Route::post('/import/preview', [ContactController::class, 'previewImport'])
                ->name('import.preview');

            Route::post('/import', [ContactController::class, 'processImport'])
                ->name('import.process');

            Route::get('/{contact}', [ContactController::class, 'show'])
                ->name('show');

            Route::post('/{contact}/notes', [ContactNoteController::class, 'store'])
                ->name('notes.store');

            Route::patch('/{contact}/notes/{note}', [ContactNoteController::class, 'update'])
                ->name('notes.update');

            Route::delete('/{contact}/notes/{note}', [ContactNoteController::class, 'destroy'])
                ->name('notes.destroy');
        });
});