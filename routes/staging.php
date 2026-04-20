<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/staging-login', function () {
    return view('staging.login');
})->name('staging.login');

Route::post('/staging-login', function (Request $request) {
    if (
        $request->input('user') === config('staging.user') &&
        $request->input('password') === config('staging.password')
    ) {
        session(['staging_access' => true]);

        return redirect()->intended('/');
    }

    return back()->withErrors([
        'login' => 'Invalid credentials',
    ]);
})->name('staging.login.submit');