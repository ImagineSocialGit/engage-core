<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Services\Email\EmailProviderManager;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\Messaging\Services\Sms\SmsProviderManager;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class MessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('messaging/sms.php'), 'messaging.sms');
        $this->mergeConfigFrom(config_path('messaging/email.php'), 'messaging.email');

        $this->app->singleton(Client::class, function () {
            return new Client(
                config('services.twilio.sid'),
                config('services.twilio.token'),
            );
        });

        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(SmsProviderManager::class, function () {
            return SmsProviderManager::default();
        });

        $this->app->singleton(EmailProviderManager::class);

        $this->app->singleton(EmailWebhookHandlerResolver::class);
    }

    public function boot(): void
    {
        //
    }
}