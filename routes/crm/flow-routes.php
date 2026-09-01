<?php

use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteBindingController;
use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteController;
use App\Modules\FlowRoutes\Controllers\CRM\FlowRouteEditorController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:flow_routes')
    ->prefix('flow-routes')
    ->name('crm.flow-routes.')
    ->group(function () {
        Route::get('/', [FlowRouteController::class, 'index'])
            ->name('index');

        Route::post('/', [FlowRouteController::class, 'store'])
            ->name('store');

        Route::get('/bindings', [FlowRouteBindingController::class, 'index'])
            ->name('bindings.index');

        Route::patch('/bindings', [FlowRouteBindingController::class, 'update'])
            ->name('bindings.update');

        Route::get('/{flowRoute}', [FlowRouteEditorController::class, 'show'])
            ->name('show');

        Route::patch('/{flowRoute}/enabled', [FlowRouteEditorController::class, 'updateEnabled'])
            ->name('enabled.update');

        Route::patch('/{flowRoute}/kind', [FlowRouteEditorController::class, 'updateKind'])
            ->name('kind.update');

        Route::delete('/{flowRoute}', [FlowRouteEditorController::class, 'destroy'])
            ->name('destroy');

        Route::patch('/{flowRoute}/start-delay', [FlowRouteEditorController::class, 'updateLeadInDelay'])
            ->name('start-delay.update');

        Route::post('/{flowRoute}/points', [FlowRouteEditorController::class, 'storePoint'])
            ->name('points.store');

        Route::patch('/{flowRoute}/points/order', [FlowRouteEditorController::class, 'reorderPoints'])
            ->name('points.order');

        Route::patch('/{flowRoute}/points/{flowRoutePoint}', [FlowRouteEditorController::class, 'updatePoint'])
            ->name('points.update');

        Route::delete('/{flowRoute}/points/{flowRoutePoint}', [FlowRouteEditorController::class, 'destroyPoint'])
            ->name('points.destroy');

        Route::patch('/{flowRoute}/points/{flowRoutePoint}/move-up', [FlowRouteEditorController::class, 'movePointUp'])
            ->name('points.move-up');

        Route::patch('/{flowRoute}/points/{flowRoutePoint}/move-down', [FlowRouteEditorController::class, 'movePointDown'])
            ->name('points.move-down');
    });