<?php

use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\LeadNoteController;
use App\Http\Controllers\CRM\LeadTaskController;
use App\Http\Controllers\CRM\WebinarController;
use App\Http\Controllers\CRM\WebinarCopyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [LeadController::class, 'index']);
    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);

    Route::get('/webinars', [WebinarController::class, 'index'])
    ->name('crm.webinars.index');

    Route::get('/webinars/{webinar}/copies/create', [WebinarCopyController::class, 'create'])
        ->name('crm.webinar.copies.create');

    Route::post('/leads/{lead}/notes', [LeadNoteController::class, 'store']);
    Route::post('/leads/{lead}/tasks', [LeadTaskController::class, 'store']);

    Route::post('/webinars/{webinar}/copies', [WebinarCopyController::class, 'store'])
        ->name('crm.webinar.copies.store');

    Route::patch('/leads/{lead}/tasks/{task}/complete', [LeadTaskController::class, 'complete']);
    Route::patch('/leads/{lead}/tasks/{task}/reopen', [LeadTaskController::class, 'reopen']);


    Route::patch(
        '/leads/{lead}/registrations/{registration}/convert',
        [LeadController::class, 'markConverted']
    );
});