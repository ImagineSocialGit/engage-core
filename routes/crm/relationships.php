<?php

use App\Modules\Relationships\Controllers\CRM\ContactRelationshipController;
use App\Modules\Relationships\Controllers\CRM\RelationshipDefinitionController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:relationships')
    ->prefix('settings/relationships')
    ->name('crm.relationships.definitions.')
    ->group(function () {
        Route::get('/', [RelationshipDefinitionController::class, 'index'])
            ->name('index');

        Route::middleware('capability:settings.manage')->group(function () {
            Route::post('/types', [RelationshipDefinitionController::class, 'storeType'])
                ->name('types.store');
            Route::put('/types/{relationshipKey}', [RelationshipDefinitionController::class, 'updateType'])
                ->name('types.update');
            Route::post('/types/{relationshipKey}/stages', [RelationshipDefinitionController::class, 'storeStage'])
                ->name('stages.store');
            Route::put('/types/{relationshipKey}/stages/{stageKey}', [RelationshipDefinitionController::class, 'updateStage'])
                ->name('stages.update');
        });
    });

Route::middleware('module:relationships')
    ->prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.relationships.')
    ->group(function () {
        Route::patch(
            '/{contact}/relationships/{contactRelationship}/stage',
            [ContactRelationshipController::class, 'updateStage'],
        )->name('stage.update');
    });