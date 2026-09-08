<?php

use App\Http\Controllers\CRM\DashboardController;
use App\Http\Controllers\CRM\ProjectStateController;
use App\Http\Controllers\CRM\ProcessHighwayController;
use App\Modules\Core\Access\Controllers\ContactAssignmentController;
use App\Modules\Core\Access\Controllers\TeamAccessController;
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

Route::get('/settings/team', [TeamAccessController::class, 'index'])
    ->name('crm.settings.team.index');

Route::post('/settings/team/members', [TeamAccessController::class, 'storeMember'])
    ->middleware('capability:team.manage')
    ->name('crm.settings.team.members.store');

Route::patch('/settings/team/members/{user}', [TeamAccessController::class, 'updateMember'])
    ->middleware('capability:team.manage')
    ->name('crm.settings.team.members.update');

Route::post('/settings/team/teams', [TeamAccessController::class, 'storeTeam'])
    ->middleware('capability:team.manage')
    ->name('crm.settings.team.teams.store');

Route::patch('/settings/team/teams/{team}', [TeamAccessController::class, 'updateTeam'])
    ->middleware('capability:team.manage')
    ->name('crm.settings.team.teams.update');


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
            ->middleware('capability:contacts.manage')
            ->name('store');

        Route::get('/lookup', ContactLookupController::class)
            ->name('lookup');

        Route::get('/import', [ContactController::class, 'import'])
            ->middleware('capability:contacts.import')
            ->name('import');

        Route::post('/import/preview', [ContactController::class, 'previewImport'])
            ->middleware('capability:contacts.import')
            ->name('import.preview');

        Route::post('/import', [ContactController::class, 'processImport'])
            ->middleware('capability:contacts.import')
            ->name('import.process');

        Route::get('/import-batches', [ContactImportBatchController::class, 'index'])
            ->middleware('capability:contacts.import')
            ->name('import-batches.index');

        Route::get('/import-batches/{contactImportBatch}', [ContactImportBatchController::class, 'show'])
            ->middleware('capability:contacts.import')
            ->name('import-batches.show');

        Route::get('/{contact}', [ContactController::class, 'show'])
            ->name('show');

        Route::patch('/{contact}', [ContactController::class, 'update'])
            ->middleware('capability:contacts.manage')
            ->name('update');

        Route::patch('/{contact}/assignment', ContactAssignmentController::class)
            ->middleware('capability:contacts.assign')
            ->name('assignment.update');

        Route::patch('/{contact}/status', [ContactController::class, 'updateStatus'])
            ->middleware(['module:workflow', 'capability:contacts.manage'])
            ->name('status.update');

        Route::post('/{contact}/notes', [ContactNoteController::class, 'store'])
            ->middleware('capability:contacts.manage')
            ->name('notes.store');

        Route::patch('/{contact}/notes/{note}', [ContactNoteController::class, 'update'])
            ->middleware('capability:contacts.manage')
            ->name('notes.update');

        Route::delete('/{contact}/notes/{note}', [ContactNoteController::class, 'destroy'])
            ->middleware('capability:contacts.manage')
            ->name('notes.destroy');
    });