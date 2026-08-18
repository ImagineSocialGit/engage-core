<?php

use App\Modules\Scheduling\Controllers\CRM\AppointmentController;
use App\Modules\Scheduling\Controllers\CRM\SchedulingAvailabilityController;
use App\Modules\Scheduling\Controllers\CRM\SchedulingConfigurationController;
use App\Modules\Scheduling\Controllers\CRM\SchedulingResourceController;
use App\Modules\Scheduling\Controllers\CRM\SchedulingController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:scheduling')
    ->prefix('scheduling')
    ->name('crm.scheduling.')
    ->group(function () {
        Route::get('/', [SchedulingController::class, 'index'])
            ->name('index');

        Route::post('/appointments', [SchedulingController::class, 'store'])
            ->name('appointments.store');

        Route::get('/configuration', [SchedulingConfigurationController::class, 'index'])
            ->name('configuration.index');

        Route::get('/configuration/resources', [SchedulingResourceController::class, 'index'])
            ->name('configuration.resources.index');

        Route::post('/configuration/resources', [SchedulingResourceController::class, 'store'])
            ->name('configuration.resources.store');

        Route::patch('/configuration/resources/{schedulingResource}', [SchedulingResourceController::class, 'update'])
            ->name('configuration.resources.update');

        Route::put('/configuration/resources/hosts/{schedulingHost}', [SchedulingResourceController::class, 'updateHostResources'])
            ->name('configuration.resources.hosts.update');

        Route::put('/configuration/resources/services/{bookableService}', [SchedulingResourceController::class, 'updateServiceRequirements'])
            ->name('configuration.resources.services.update');

        Route::get('/configuration/availability', [SchedulingAvailabilityController::class, 'index'])
            ->name('configuration.availability.index');

        Route::post('/configuration/availability', [SchedulingAvailabilityController::class, 'store'])
            ->name('configuration.availability.store');

        Route::patch('/configuration/availability/{availabilityWindow}', [SchedulingAvailabilityController::class, 'update'])
            ->name('configuration.availability.update');

        Route::delete('/configuration/availability/{availabilityWindow}', [SchedulingAvailabilityController::class, 'archive'])
            ->name('configuration.availability.archive');

        Route::post('/configuration/availability/{availabilityWindow}/restore', [SchedulingAvailabilityController::class, 'restore'])
            ->withTrashed()
            ->name('configuration.availability.restore');

        Route::post('/configuration/hosts', [SchedulingConfigurationController::class, 'storeHost'])
            ->name('configuration.hosts.store');

        Route::patch('/configuration/hosts/{schedulingHost}', [SchedulingConfigurationController::class, 'updateHost'])
            ->name('configuration.hosts.update');

        Route::post('/configuration/services', [SchedulingConfigurationController::class, 'storeService'])
            ->name('configuration.services.store');

        Route::patch('/configuration/services/{bookableService}', [SchedulingConfigurationController::class, 'updateService'])
            ->name('configuration.services.update');

        Route::put('/configuration/services/{bookableService}/hosts', [SchedulingConfigurationController::class, 'updateServiceHosts'])
            ->name('configuration.services.hosts.update');

        Route::get('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
            ->name('appointments.reschedule');

        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'storeReschedule'])
            ->name('appointments.reschedule.store');

        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])
            ->name('appointments.show');

        Route::patch('/appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])
            ->name('appointments.confirm');

        Route::patch('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');

        Route::patch('/appointments/{appointment}/complete', [AppointmentController::class, 'complete'])
            ->name('appointments.complete');

        Route::patch('/appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])
            ->name('appointments.no-show');
    });