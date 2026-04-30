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
            $domain = parse_url(config('app.url'), PHP_URL_HOST);

            Route::middleware(['web'])
                ->group(function () {
                    require base_path('routes/staging.php');
                });

            Route::middleware(['web'])
                ->domain('webinar.' . $domain)
                ->group(function () {
                    require base_path('routes/webinar.php');
                });

            Route::middleware(['web', 'auth'])
                ->domain('crm.' . $domain)
                ->group(function () {
                    require base_path('routes/crm.php');
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staging.access' => \App\Http\Middleware\ForceStagingAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/zoom',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\ForceStagingAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();