<?php

use App\Modules\Commerce\Controllers\CRM\CommerceController;
use App\Modules\Commerce\Controllers\CRM\CommerceOrderController;
use App\Modules\Commerce\Controllers\CRM\CommerceProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:commerce')
    ->prefix('commerce')
    ->name('crm.commerce.')
    ->group(function () {
        Route::get('/', CommerceController::class)
            ->name('index');

        Route::get('/orders', [CommerceOrderController::class, 'index'])
            ->name('orders.index');
        Route::get('/orders/{commerceOrder}', [CommerceOrderController::class, 'show'])
            ->name('orders.show');

        Route::get('/products/{commerceProduct}', CommerceProductController::class)
            ->name('products.show');
    });