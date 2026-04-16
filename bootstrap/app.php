<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {
            Route::middleware('web')
                ->domain('webinar.' . parse_url(config('app.url'), PHP_URL_HOST))
                ->group(base_path('routes/webinar.php'));

            Route::middleware('web')
                ->domain('crm.' . parse_url(config('app.url'), PHP_URL_HOST))
                ->group(base_path('routes/crm.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
