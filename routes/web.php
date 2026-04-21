<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Webhooks\ZoomWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth');

Route::post('/webhooks/zoom', ZoomWebhookController::class)
    ->name('webhooks.zoom');