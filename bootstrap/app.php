<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\ForceStagingAccess;
use App\Support\Clients\ClientEnvironmentLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {
            $domain = config('app.root_domain');

            Route::middleware(['web'])
                ->group(function () {
                    require base_path('routes/staging.php');
                });

            Route::middleware(['web'])
                ->domain('webhooks.'.$domain)
                ->group(function () {
                    require base_path('routes/webhooks.php');
                });

            Route::middleware(['web'])
                ->domain('webinar.'.$domain)
                ->group(function () {
                    require base_path('routes/webinar.php');
                });

            Route::middleware(['web'])
                ->domain('crm.'.$domain)
                ->group(function () {
                    require base_path('routes/crm.php');
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'staging.access' => ForceStagingAccess::class,
            'module' => EnsureModuleEnabled::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webinar/zoom',
            'sms/telnyx',
            'sms/twilio',
            'email/resend',
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

$app->afterLoadingEnvironment(function () use ($app): void {
    /*
     * Cached configuration already contains the fully resolved selected-client
     * environment and merged config. Re-loading .env files at runtime would
     * contradict Laravel's config-cache model.
     */
    if (
        $app->configurationIsCached()
        || Env::get('APP_ENV') === 'testing'
    ) {
        return;
    }

    (new ClientEnvironmentLoader())->load($app->basePath());
});

return $app;
