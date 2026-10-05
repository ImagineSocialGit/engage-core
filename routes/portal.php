<?php

use App\Modules\Portal\Controllers\Public\PortalAccountController;
use App\Modules\Portal\Controllers\Public\PortalEmailVerificationController;
use App\Modules\Portal\Controllers\Public\PortalHomeController;
use App\Modules\Portal\Controllers\Public\PortalInvitationAcceptanceController;
use App\Modules\Portal\Controllers\Public\PortalPasswordResetController;
use App\Modules\Portal\Controllers\Public\PortalSessionController;
use App\Modules\Portal\Http\Middleware\RequirePortalAuthentication;
use App\Modules\Portal\Services\PortalRouteRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware('module:portal')
    ->name('portal.')
    ->group(function (): void {
        Route::get('/login', [PortalSessionController::class, 'create'])->name('login');
        Route::post('/login', [PortalSessionController::class, 'store'])->name('login.store');

        Route::get('/forgot-password', [PortalPasswordResetController::class, 'requestForm'])
            ->name('password.request.form');
        Route::post('/forgot-password', [PortalPasswordResetController::class, 'request'])
            ->middleware('throttle:5,1')
            ->name('password.request');
        Route::get('/reset-password/{token}', [PortalPasswordResetController::class, 'resetForm'])
            ->where('token', '[A-Za-z0-9_-]{20,2048}')
            ->name('password.reset');
        Route::post('/reset-password/{token}', [PortalPasswordResetController::class, 'reset'])
            ->where('token', '[A-Za-z0-9_-]{20,2048}')
            ->name('password.update');

        Route::get('/activate/{invitation}/{token}', [PortalInvitationAcceptanceController::class, 'show'])
            ->whereNumber('invitation')
            ->where('token', '[A-Za-z0-9_-]{20,2048}')
            ->name('invitations.accept');
        Route::post('/activate/{invitation}/{token}', [PortalInvitationAcceptanceController::class, 'store'])
            ->whereNumber('invitation')
            ->where('token', '[A-Za-z0-9_-]{20,2048}')
            ->name('invitations.accept.store');

        Route::middleware(RequirePortalAuthentication::class)
            ->group(function (): void {
                Route::get('/', PortalHomeController::class)->name('home');
                Route::get('/account', [PortalAccountController::class, 'show'])->name('account.show');
                Route::patch('/account', [PortalAccountController::class, 'update'])->name('account.update');
                Route::post('/logout', [PortalSessionController::class, 'destroy'])->name('logout');

                Route::get('/email/verify', [PortalEmailVerificationController::class, 'notice'])
                    ->name('verification.notice');
                Route::post('/email/verification-notification', [PortalEmailVerificationController::class, 'send'])
                    ->middleware('throttle:6,1')
                    ->name('verification.send');
                Route::get('/email/verify/{id}/{hash}', [PortalEmailVerificationController::class, 'verify'])
                    ->whereNumber('id')
                    ->where('hash', '[A-Fa-f0-9]{40}')
                    ->name('verification.verify');

                if (app()->bound(PortalRouteRegistry::class)) {
                    app(PortalRouteRegistry::class)->registerRoutes();
                }
            });
    });