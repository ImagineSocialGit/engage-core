<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\ForceStagingAccess;
use App\Http\Middleware\RequestCorrelation;
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
                ->domain('messaging.'.$domain)
                ->group(function () {
                    require base_path('routes/messaging.php');
                });

            $crmHost = parse_url(
                (string) config('app.crm_url'),
                PHP_URL_HOST,
            );

            $crmHost = is_string($crmHost) && $crmHost !== ''
                ? $crmHost
                : 'crm.'.$domain;

            Route::middleware(['web'])
                ->domain($crmHost)
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
            'message-events/email/resend',
            'message-events/sms/telnyx',
            'inbound/email/resend',
            'inbound/sms/telnyx',
            'sms/telnyx',
            'sms/twilio',
            'email/resend',
            'forms/*/submissions',
        ]);

        $middleware->web(
            prepend: [
                RequestCorrelation::class,
            ],
            append: [
                ForceStagingAccess::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            InvalidSignatureException $exception,
            $request
        ) {
            $routeName = $request->route()?->getName();

            $view = match (true) {
                in_array($routeName, [
                    'messaging.email.unsubscribe',
                    'messaging.email.unsubscribe.store',
                ], true) => 'messaging.unsubscribe-invalid',

                in_array($routeName, [
                    'messaging.email.transactional-opt-out',
                    'messaging.email.transactional-opt-out.store',
                ], true) => 'messaging.transactional-opt-out-invalid',

                str_starts_with(
                    (string) $routeName,
                    'webinar.'
                ) => 'webinar.signed-link-invalid',

                default => null,
            };

            if ($view === null) {
                return null;
            }

            return response()->view(
                $view,
                status: 403,
            );
        });
    })
    ->create();

$app->afterLoadingEnvironment(function () use ($app): void {
    if (
        $app->configurationIsCached()
        || Env::get('APP_ENV') === 'testing'
    ) {
        return;
    }

    (new ClientEnvironmentLoader())->load($app->basePath());
});

return $app;