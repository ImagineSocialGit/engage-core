<?php

use App\Modules\Messaging\Controllers\CRM\CreateFlowRouteMessageTemplateController;
use App\Modules\Messaging\Controllers\CRM\MessageDeliveryIssueController;
use App\Modules\Messaging\Controllers\CRM\MessageTemplatePresetController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:messaging')
    ->prefix('messaging/delivery-issues')
    ->name('crm.messaging.delivery-issues.')
    ->group(function () {
        Route::get('/', [MessageDeliveryIssueController::class, 'index'])
            ->name('index');

        Route::post('/{messageSuppression}/release', [MessageDeliveryIssueController::class, 'release'])
            ->name('release');
    });

Route::middleware('module:messaging')
    ->prefix('message-templates')
    ->name('crm.messaging.message-templates.')
    ->group(function () {
        Route::get('/', [MessageTemplatePresetController::class, 'index'])
            ->name('index');

        Route::post('/reusable/flow-route', CreateFlowRouteMessageTemplateController::class)
            ->middleware('module:flow_routes')
            ->name('flow-route.store');

        Route::patch(
            '/composition-layers/{messageTemplateCompositionLayer}',
            [MessageTemplatePresetController::class, 'updateCompositionLayer'],
        )->name('composition-layers.update');

        Route::patch('/{messageTemplatePreset}', [MessageTemplatePresetController::class, 'update'])
            ->name('update');
    });