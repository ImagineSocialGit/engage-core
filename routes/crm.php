<?php

use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\LeadNoteController;
use App\Http\Controllers\CRM\LeadTaskController;
use App\Http\Controllers\CRM\WebinarController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('crm.index');;
    Route::get('/leads', [LeadController::class, 'index'])->name('crm.leads.index');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('crm.leads.show');

    Route::get('/webinars', [WebinarController::class, 'index'])
        ->name('crm.webinar-series.index');

    Route::post('/webinar-series', [WebinarController::class, 'storeSeries'])
        ->name('crm.webinar-series.store');

    Route::post('/webinar-series/sync', [WebinarController::class, 'syncSeries'])
        ->name('crm.webinar-series.sync');

    Route::post('/webinar-series/{series}/fix-active', [WebinarController::class, 'fixActive'])
        ->name('crm.webinar-series.fix-active');

    Route::post('/leads/{lead}/notes', [LeadNoteController::class, 'store'])->name('crm.leads.notes.store');;
    Route::post('/leads/{lead}/tasks', [LeadTaskController::class, 'store'])->name('crm.leads.tasks.store');

    Route::patch('/leads/{lead}/tasks/{task}/complete', [LeadTaskController::class, 'complete'])->name('crm.leads.tasks.complete');
    Route::patch('/leads/{lead}/tasks/{task}/reopen', [LeadTaskController::class, 'reopen'])->name('crm.leads.tasks.reopen');

    Route::patch(
        '/leads/{lead}/registrations/{registration}/convert',
        [LeadController::class, 'markConverted']
    )->name('crm.leads.registrations.convert');
});