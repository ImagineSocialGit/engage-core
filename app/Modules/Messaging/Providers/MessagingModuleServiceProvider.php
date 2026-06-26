<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Services\Email\EmailProviderManager;
use App\Modules\Messaging\Services\MessageRecipientGateRegistry;
use App\Modules\Messaging\Services\Sms\SmsProviderManager;
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

        $this->app->singleton(SmsProviderManager::class, function () {
            return SmsProviderManager::default();
        });

        $this->app->singleton(EmailProviderManager::class);

        $this->app->singleton(MessageRecipientGateRegistry::class, function ($app) {
            return new MessageRecipientGateRegistry(
                gates: $app->tagged('messaging.message_recipient_gates'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}