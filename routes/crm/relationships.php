<?php

use App\Modules\Relationships\Controllers\CRM\ContactRelationshipController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:relationships')
    ->prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.relationships.')
    ->group(function () {
        Route::patch(
            '/{contact}/relationships/{contactRelationship}/stage',
            [ContactRelationshipController::class, 'updateStage'],
        )->name('stage.update');
    });