<?php

use App\Modules\Portal\Controllers\Public\PortalAccountController;
use App\Modules\Portal\Controllers\Public\PortalHomeController;
use App\Modules\Portal\Controllers\Public\PortalInvitationAcceptanceController;
use App\Modules\Portal\Controllers\Public\PortalSessionController;
use App\Modules\Portal\Http\Middleware\RequirePortalAuthentication;
use App\Modules\Portal\Services\PortalRouteRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware('module:portal')
    ->name('portal.')
    ->group(function (): void {
        Route::get('/login', [PortalSessionController::class, 'create'])->name('login');
        Route::post('/login', [PortalSessionController::class, 'store'])->name('login.store');

        Route::get('/activate/{invitation}/{token}', [PortalInvitationAcceptanceController::class, 'show'])
            ->whereNumber('invitation')
            ->where('token', '[A-Za-z0-9]{20,255}')
            ->name('invitations.accept');
        Route::post('/activate/{invitation}/{token}', [PortalInvitationAcceptanceController::class, 'store'])
            ->whereNumber('invitation')
            ->where('token', '[A-Za-z0-9]{20,255}')
            ->name('invitations.accept.store');

        Route::middleware(RequirePortalAuthentication::class)
            ->group(function (): void {
                Route::get('/', PortalHomeController::class)->name('home');
                Route::get('/account', [PortalAccountController::class, 'show'])->name('account.show');
                Route::patch('/account', [PortalAccountController::class, 'update'])->name('account.update');
                Route::post('/logout', [PortalSessionController::class, 'destroy'])->name('logout');

                if (app()->bound(PortalRouteRegistry::class)) {
                    app(PortalRouteRegistry::class)->registerRoutes();
                }
            });
    });