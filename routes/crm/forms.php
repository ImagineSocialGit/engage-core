<?php

use App\Modules\Forms\Controllers\CRM\FormsController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:forms')
    ->prefix('forms')
    ->name('crm.forms.')
    ->group(function () {
        Route::get('/', FormsController::class)->name('index');
    });