<?php

namespace App\Modules\InboundMessaging\Providers;

use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use Illuminate\Support\ServiceProvider;

class InboundMessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(EmailWebhookHandlerResolver::class);
    }

    public function boot(): void
    {
        //
    }
}