<?php

use App\Modules\Events\Controllers\CRM\EventController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:events')
    ->prefix('events')
    ->name('crm.events.')
    ->group(function () {
        Route::get('/', [EventController::class, 'index'])
            ->name('index');

        Route::get('/create', [EventController::class, 'create'])
            ->name('create');

        Route::post('/', [EventController::class, 'store'])
            ->name('store');

        Route::get('/{event}', [EventController::class, 'show'])
            ->name('show');

        Route::get('/{event}/edit', [EventController::class, 'edit'])
            ->name('edit');

        Route::patch('/{event}', [EventController::class, 'update'])
            ->name('update');

        Route::post('/{event}/promote', [EventController::class, 'promote'])
            ->name('promote');
    });