<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhooks\ZoomWebhookController;

Route::get('/', function () {
    return view('welcome');
});


Route::post('/webhooks/zoom', ZoomWebhookController::class)
    ->name('webhooks.zoom');