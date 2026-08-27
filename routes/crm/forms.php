<?php

use App\Modules\Forms\Controllers\CRM\FormsController;
use App\Modules\Forms\Controllers\CRM\FormSubmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:forms')
    ->prefix('forms')
    ->name('crm.forms.')
    ->group(function () {
        Route::get('/', FormsController::class)->name('index');

        Route::get('/{formDefinition:key}/submissions', [FormSubmissionController::class, 'index'])
            ->name('submissions.index');

        Route::get('/submissions/{formSubmission}', [FormSubmissionController::class, 'show'])
            ->name('submissions.show');
    });