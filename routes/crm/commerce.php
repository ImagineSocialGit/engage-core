<?php

use App\Modules\Commerce\Controllers\CRM\CommerceController;
use App\Modules\Commerce\Controllers\CRM\CommerceProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:commerce')
    ->prefix('commerce')
    ->name('crm.commerce.')
    ->group(function () {
        Route::get('/', CommerceController::class)
            ->name('index');

        Route::get('/products/{commerceProduct}', CommerceProductController::class)
            ->name('products.show');
    });