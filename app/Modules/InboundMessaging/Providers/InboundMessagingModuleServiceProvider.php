<?php

namespace App\Modules\InboundMessaging\Providers;

use App\Modules\InboundMessaging\Console\Commands\SyncInboundReplyProfilesCommand;
use App\Modules\InboundMessaging\Services\ContactShow\ContactConversationShowDataProvider;
use App\Modules\InboundMessaging\Services\Dashboard\LeadRepliesDashboardPanelProvider;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use App\Modules\InboundMessaging\Validation\InboundMessagingSetupValidationContributor;
use App\Support\Dashboard\DashboardPanelRegistry;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Support\ServiceProvider;

class InboundMessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(EmailWebhookHandlerResolver::class);

        $this->app->singleton(
            ReplyProfileDependencyRegistry::class,
            fn ($app): ReplyProfileDependencyRegistry => new ReplyProfileDependencyRegistry(
                $app->tagged(ReplyProfileDependencyRegistry::CONTRIBUTOR_TAG),
            ),
        );

        $this->app->tag([
            LeadRepliesDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());

        $this->app->tag([
            ContactConversationShowDataProvider::class,
        ], 'core.contact_show_data_providers');

        $this->app->tag([
            InboundMessagingSetupValidationContributor::class,
        ], 'setup.validation_contributors');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncInboundReplyProfilesCommand::class,
            ]);
        }
    }
}