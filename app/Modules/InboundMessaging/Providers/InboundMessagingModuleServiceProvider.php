<?php

namespace App\Modules\InboundMessaging\Providers;

use App\Modules\InboundMessaging\Services\ContactShow\ContactConversationShowDataProvider;
use App\Modules\InboundMessaging\Services\Dashboard\LeadRepliesDashboardPanelProvider;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Support\ServiceProvider;

class InboundMessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(EmailWebhookHandlerResolver::class);

        $this->app->tag([
            LeadRepliesDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());

        $this->app->tag([
            ContactConversationShowDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        //
    }
}