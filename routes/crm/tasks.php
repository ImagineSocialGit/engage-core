<?php

use App\Http\Controllers\CRM\DashboardController;
use App\Modules\Tasks\Controllers\ContactResultTaskActionController;
use App\Modules\Tasks\Controllers\TaskController;
use App\Modules\Tasks\Controllers\TaskTemplateController;
use Illuminate\Support\Facades\Route;

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

        Route::get('/templates', [TaskTemplateController::class, 'index'])
            ->name('templates.index');

        Route::get('/templates/create', [TaskTemplateController::class, 'create'])
            ->name('templates.create');

        Route::post('/templates', [TaskTemplateController::class, 'store'])
            ->name('templates.store');

        Route::post('/contact-results', ContactResultTaskActionController::class)
            ->middleware('capability:contacts.manage')
            ->name('contact-results.store');

        Route::get('/templates/{taskTemplate}/edit', [TaskTemplateController::class, 'edit'])
            ->name('templates.edit');

        Route::patch('/templates/{taskTemplate}', [TaskTemplateController::class, 'update'])
            ->name('templates.update');

        Route::get('/{task}', [TaskController::class, 'show'])
            ->name('show');

        Route::patch('/{task}/assignment', [TaskController::class, 'updateAssignment'])
            ->name('assignment.update');

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