<?php

use App\Modules\Documents\Controllers\CRM\DocumentLibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:documents')
    ->prefix('documents')
    ->name('crm.documents.')
    ->group(function (): void {
        Route::get('/', [DocumentLibraryController::class, 'index'])->name('index');
        Route::post('/', [DocumentLibraryController::class, 'store'])->name('store');
        Route::get('/{documentUpload}/download', [DocumentLibraryController::class, 'download'])->name('download');
    });