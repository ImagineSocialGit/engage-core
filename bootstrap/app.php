<?php

use App\Http\Middleware\ForceStagingAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {
            $domain = config('app.root_domain');

            Route::middleware(['web'])
                ->domain('webhooks.'.$domain)
                ->group(function () {
                    require base_path('routes/webhooks.php');
                });

            Route::middleware(['web'])
                ->domain('webinar.'.$domain)
                ->name('webinar.')
                ->group(function () {
                    require base_path('routes/staging.php');
                    require base_path('routes/webinar.php');
                });

            Route::middleware(['web'])
                ->domain('crm.'.$domain)
                ->name('crm.')
                ->group(function () {
                    require base_path('routes/staging.php');
                    require base_path('routes/crm.php');
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staging.access' => ForceStagingAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/zoom',
            'webhooks/twilio/sms',
        ]);

        $middleware->web(append: [
            ForceStagingAccess::class,
        ]);
    })
    ->withExceptions(function ($exceptions): void {
        $exceptions->render(function (
            InvalidSignatureException $exception,
            $request
        ) {
            return response()->view(
                'messaging.unsubscribe-invalid',
                status: 403,
            );
        });
    })
    ->create();