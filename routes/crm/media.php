<?php

use App\Modules\Media\Controllers\CRM\MediaAssetController;
use App\Modules\Media\Controllers\CRM\MediaUploadSimilarityController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:media')
    ->prefix('media')
    ->name('crm.media.')
    ->group(function (): void {
        Route::get('/', [MediaAssetController::class, 'index'])
            ->name('index');

        Route::post('/similarity/inspect', MediaUploadSimilarityController::class)
            ->name('similarity.inspect');

        Route::post('/', [MediaAssetController::class, 'store'])
            ->name('store');

        Route::patch('/{mediaAsset}/archive', [MediaAssetController::class, 'archive'])
            ->name('archive');

        Route::patch('/{mediaAsset}/restore', [MediaAssetController::class, 'restore'])
            ->name('restore');
    });