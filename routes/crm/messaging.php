<?php

use App\Modules\Messaging\Controllers\ContactImportBatchPermissionInvitationController;
use App\Modules\Messaging\Controllers\CRM\MessageTemplatePresetController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:messaging')
    ->prefix('message-templates')
    ->name('crm.messaging.message-templates.')
    ->group(function () {
        Route::get('/', [MessageTemplatePresetController::class, 'index'])
            ->name('index');

        Route::patch('/{messageTemplatePreset}', [MessageTemplatePresetController::class, 'update'])
            ->name('update');
    });

// Messaging-owned extension of the Contacts import-batch workspace.
Route::prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.')
    ->middleware('module:messaging')
    ->group(function () {
        Route::post('/import-batches/{contactImportBatch}/permission-invitations', ContactImportBatchPermissionInvitationController::class)
            ->name('import-batches.permission-invitations.store');

        Route::delete('/import-batches/{contactImportBatch}/permission-invitations', [ContactImportBatchPermissionInvitationController::class, 'destroy'])
            ->name('import-batches.permission-invitations.destroy');
    });