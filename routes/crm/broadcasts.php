<?php

use App\Modules\Broadcasts\Controllers\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:broadcasts')
    ->prefix('broadcasts')
    ->name('crm.broadcasts.')
    ->group(function () {
        Route::get('/', [BroadcastController::class, 'index'])
            ->name('index');

        Route::post('/audience-preview', [BroadcastController::class, 'previewAudience'])
            ->name('audience-preview');

        Route::post('/', [BroadcastController::class, 'store'])
            ->name('store');

        Route::post('/{broadcast}/save-message-template', [BroadcastController::class, 'saveMessageTemplate'])
            ->name('save-message-template');

        Route::post('/{broadcast}/duplicate', [BroadcastController::class, 'duplicate'])
            ->name('duplicate');

        Route::get('/{broadcast}', [BroadcastController::class, 'show'])
            ->name('show');

        Route::get('/{broadcast}/edit', [BroadcastController::class, 'edit'])
            ->name('edit');

        Route::patch('/{broadcast}', [BroadcastController::class, 'update'])
            ->name('update');

        Route::patch('/{broadcast}/schedule', [BroadcastController::class, 'schedule'])
            ->name('schedule');

        Route::patch('/{broadcast}/cancel', [BroadcastController::class, 'cancel'])
            ->name('cancel');
    });