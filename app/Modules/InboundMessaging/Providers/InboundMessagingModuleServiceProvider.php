<?php

namespace App\Modules\InboundMessaging\Providers;

use App\Modules\InboundMessaging\Automation\InboundMessagingAutomationPointDefinitionContributor;
use App\Modules\InboundMessaging\Automation\InboundReplyAutomationTriggerAuthoringContributor;
use App\Modules\InboundMessaging\Automation\MarkInboundMessageAutoRespondedActionHandler;
use App\Modules\InboundMessaging\Capabilities\InboundMessagingAutomationCapabilityContributor;
use App\Modules\InboundMessaging\Console\Commands\SyncInboundReplyProfilesCommand;
use App\Modules\InboundMessaging\Deployment\InboundMessagingDeploymentPlanContributor;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Listeners\ConsumeRoutedInboundMessage;
use App\Modules\InboundMessaging\Listeners\RecordInboundAutomaticMessage;
use App\Modules\InboundMessaging\Services\ContactShow\ContactConversationShowDataProvider;
use App\Modules\InboundMessaging\Services\Dashboard\LeadRepliesDashboardPanelProvider;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\InboundMessaging\Services\Email\RoutedInboundMessageConsumerRegistry;
use App\Modules\InboundMessaging\Services\ReplyProfiles\InboundReplyProfilePresentationProvider;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use App\Modules\InboundMessaging\Validation\InboundMessagingSetupValidationContributor;
use App\Modules\Messaging\Events\AutomationMessageScheduled;
use App\Support\Dashboard\DashboardPanelRegistry;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use App\Support\ReplyHandling\ReplyProfilePresentationRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InboundMessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            InboundReplyAutomationTriggerAuthoringContributor::class,
        ], 'automation.trigger_authoring_contributors');

        $this->app->tag([
            InboundMessagingAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            InboundMessagingAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            MarkInboundMessageAutoRespondedActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(EmailWebhookHandlerResolver::class);

        $this->app->singleton(
            RoutedInboundMessageConsumerRegistry::class,
            fn ($app): RoutedInboundMessageConsumerRegistry =>
                new RoutedInboundMessageConsumerRegistry(
                    $app->tagged(
                        RoutedInboundMessageConsumerRegistry::CONSUMER_TAG,
                    ),
                ),
        );

        $this->app->singleton(
            ReplyProfileDependencyRegistry::class,
            fn ($app): ReplyProfileDependencyRegistry => new ReplyProfileDependencyRegistry(
                $app->tagged(ReplyProfileDependencyRegistry::CONTRIBUTOR_TAG),
            ),
        );

        $this->app->tag([
            InboundReplyProfilePresentationProvider::class,
        ], ReplyProfilePresentationRegistry::PROVIDER_TAG);

        $this->app->singleton(
            ReplyProfilePresentationRegistry::class,
            fn ($app): ReplyProfilePresentationRegistry => new ReplyProfilePresentationRegistry(
                $app->tagged(ReplyProfilePresentationRegistry::PROVIDER_TAG),
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

        $this->app->tag([
            InboundMessagingDeploymentPlanContributor::class,
        ], 'deployment.plan_contributors');
    }

    public function boot(): void
    {
        Event::listen(
            InboundMessageReceived::class,
            ConsumeRoutedInboundMessage::class,
        );
        Event::listen(
            AutomationMessageScheduled::class,
            RecordInboundAutomaticMessage::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncInboundReplyProfilesCommand::class,
            ]);
        }
    }
}