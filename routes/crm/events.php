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

        Route::post('/{event}/postpone', [EventController::class, 'postpone'])
            ->name('postpone');

        Route::post('/{event}/reschedule', [EventController::class, 'reschedule'])
            ->name('reschedule');

        Route::post('/{event}/cancel', [EventController::class, 'cancel'])
            ->name('cancel');

        Route::post('/{event}/complete', [EventController::class, 'complete'])
            ->name('complete');

        Route::post('/{event}/external-references', [EventController::class, 'storeExternalReference'])
            ->name('external-references.store');

        Route::patch(
            '/{event}/external-references/{externalReference}',
            [EventController::class, 'updateExternalReference'],
        )->name('external-references.update');

        Route::delete(
            '/{event}/external-references/{externalReference}',
            [EventController::class, 'destroyExternalReference'],
        )->name('external-references.destroy');

        Route::patch(
            '/{event}/primary-external-reference',
            [EventController::class, 'updatePrimaryExternalReference'],
        )->name('external-references.primary');

        Route::post('/{event}/stakeholders', [EventController::class, 'storeStakeholder'])
            ->name('stakeholders.store');

        Route::patch(
            '/{event}/stakeholders/{stakeholder}',
            [EventController::class, 'updateStakeholder'],
        )->name('stakeholders.update');

        Route::delete(
            '/{event}/stakeholders/{stakeholder}',
            [EventController::class, 'destroyStakeholder'],
        )->name('stakeholders.destroy');

        Route::post('/{event}/attendance', [EventController::class, 'recordAttendance'])
            ->name('attendance.store');
    });