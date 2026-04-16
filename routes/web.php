<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Webhooks\ZoomWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth');

Route::get('/staging-login', function () {
    return view('staging.login');
})->name('staging.login');

Route::post('/staging-login', function (Request $request) {

    if (
        $request->input('user') === config('staging.user') &&
        $request->input('password') === config('staging.password')
    ) {
        session(['staging_access' => true]);

        return redirect('/')->with('test', 'ok');
    }

    dd('failed');

    return back()->withErrors([
        'login' => 'Invalid credentials',
    ]);
})->name('staging.login.submit');

Route::post('/webhooks/zoom', ZoomWebhookController::class)
    ->name('webhooks.zoom');