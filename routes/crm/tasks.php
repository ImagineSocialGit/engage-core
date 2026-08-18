<?php

use App\Http\Controllers\CRM\DashboardController;
use App\Modules\Tasks\Controllers\TaskController;
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

        Route::get('/{task}', [TaskController::class, 'show'])
            ->name('show');

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