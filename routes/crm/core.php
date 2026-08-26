<?php

use App\Http\Controllers\CRM\DashboardController;
use App\Http\Controllers\CRM\ProjectStateController;
use App\Http\Controllers\CRM\ProcessHighwayController;
use App\Modules\Core\Controllers\BusinessCalendarController;
use App\Modules\Core\Controllers\ContactController;
use App\Modules\Core\Controllers\ContactImportBatchController;
use App\Modules\Core\Controllers\ContactLookupController;
use App\Modules\Core\Controllers\ContactNoteController;
use App\Modules\Core\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('crm.index');

Route::post('/dashboard/acknowledgements', [DashboardController::class, 'acknowledge'])
    ->name('crm.dashboard.acknowledgements.store');

Route::get('/process-highway', ProcessHighwayController::class)
    ->name('crm.process-highway.index');

Route::get('/business-days', [BusinessCalendarController::class, 'edit'])
    ->name('crm.business-calendar.edit');

Route::put('/business-days', [BusinessCalendarController::class, 'update'])
    ->name('crm.business-calendar.update');

Route::get('/settings', SettingsController::class)
    ->name('crm.settings.index');

Route::get('/project-state', [ProjectStateController::class, 'index'])
    ->name('crm.project-state.index');

Route::post('/project-state/export', [ProjectStateController::class, 'export'])
    ->middleware('throttle:5,1')
    ->name('crm.project-state.export');

Route::post('/project-state/import', [ProjectStateController::class, 'import'])
    ->middleware('throttle:5,1')
    ->name('crm.project-state.import');

Route::post('/project-state/resume', [ProjectStateController::class, 'resume'])
    ->middleware('throttle:5,1')
    ->name('crm.project-state.resume');

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