<?php

use App\Modules\Messaging\Controllers\ContactImportBatchPermissionInvitationController;
use App\Modules\Messaging\Controllers\CRM\CreateFlowRouteMessageTemplateController;
use App\Modules\Messaging\Controllers\CRM\MessageTemplatePresetController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:messaging')
    ->prefix('message-templates')
    ->name('crm.messaging.message-templates.')
    ->group(function () {
        Route::get('/', [MessageTemplatePresetController::class, 'index'])
            ->name('index');

        Route::post('/reusable/flow-route', CreateFlowRouteMessageTemplateController::class)
            ->middleware('module:flow_routes')
            ->name('flow-route.store');

        Route::patch('/composition-layers/{messageTemplateCompositionLayer}', [MessageTemplatePresetController::class, 'updateCompositionLayer'])
            ->name('composition-layers.update');

        Route::patch('/{messageTemplatePreset}', [MessageTemplatePresetController::class, 'update'])
            ->name('update');
    });

Route::prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.')
    ->middleware('module:messaging')
    ->group(function () {
        Route::post('/import-batches/{contactImportBatch}/permission-invitations', ContactImportBatchPermissionInvitationController::class)
            ->name('import-batches.permission-invitations.store');

        Route::delete('/import-batches/{contactImportBatch}/permission-invitations', [ContactImportBatchPermissionInvitationController::class, 'destroy'])
            ->name('import-batches.permission-invitations.destroy');
    });