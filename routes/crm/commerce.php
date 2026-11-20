<?php

use App\Http\Middleware\AuditProtectedDataRead;
use App\Modules\Commerce\Controllers\CRM\CommerceController;
use App\Modules\Commerce\Controllers\CRM\CommerceOperatorController;
use App\Modules\Commerce\Controllers\CRM\CommerceOrderController;
use App\Modules\Commerce\Controllers\CRM\CommerceProductController;
use App\Modules\Commerce\Controllers\CRM\CommercePrivacyRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:commerce')
    ->prefix('commerce')
    ->name('crm.commerce.')
    ->group(function () {
        Route::get('/', CommerceController::class)
            ->name('index');

        Route::get('/privacy', CommercePrivacyRequestController::class)
            ->middleware(AuditProtectedDataRead::class.':commerce_privacy,list')
            ->name('privacy.index');
        Route::post('/privacy/{receipt}/redact-customer', [CommercePrivacyRequestController::class, 'redactCustomer'])
            ->name('privacy.redact-customer');
        Route::post('/privacy/{receipt}/complete', [CommercePrivacyRequestController::class, 'complete'])
            ->name('privacy.complete');

        Route::get('/orders', [CommerceOrderController::class, 'index'])
            ->middleware(AuditProtectedDataRead::class.':commerce_order,list')
            ->name('orders.index');
        Route::get('/orders/{commerceOrder}', [CommerceOrderController::class, 'show'])
            ->middleware(AuditProtectedDataRead::class.':commerce_order,view')
            ->name('orders.show');
        Route::post('/orders/{commerceOrder}/refresh', [CommerceOperatorController::class, 'refreshOrder'])
            ->name('orders.refresh');

        Route::get('/products/{commerceProduct}', CommerceProductController::class)
            ->name('products.show');
        Route::post(
            '/products/{commerceProduct}/variants/{commerceProductVariant}/inventory',
            [CommerceOperatorController::class, 'readInventory'],
        )->name('products.variants.inventory');
    });