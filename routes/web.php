<?php

use App\Http\Controllers\Auth\LoginController;
use App\Modules\Messaging\Controllers\Public\ContactPermissionInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/preferences/{token}', [ContactPermissionInvitationController::class, 'show'])
    ->name('messaging.permission-invitations.show');

Route::post('/preferences/{token}', [ContactPermissionInvitationController::class, 'store'])
    ->name('messaging.permission-invitations.store');