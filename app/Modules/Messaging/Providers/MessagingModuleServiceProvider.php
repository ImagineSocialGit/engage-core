<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Core\Events\ManualContactCreated;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Messaging\Actions\CancelContactMessagingRuntimeAction;
use App\Modules\Messaging\Automation\MessagingAutomationPointAuthoringContributor;
use App\Modules\Messaging\Automation\MessagingAutomationPointDefinitionContributor;
use App\Modules\Messaging\Automation\SendMessageAutomationActionHandler;
use App\Modules\Messaging\Contracts\MessageTemplateDefinitionContributor;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Capabilities\MessagingAutomationCapabilityContributor;
use App\Modules\Messaging\ConfigContracts\EmailMessageDefinitionConfigContract;
use App\Modules\Messaging\ConfigContracts\MessagingConfigContractTargetProvider;
use App\Modules\Messaging\ConfigContracts\PermissionInvitationConfigContract;
use App\Modules\Messaging\ConfigContracts\SmsMessageDefinitionConfigContract;
use App\Modules\Messaging\Console\Commands\AuditEmailHygieneCommand;
use App\Modules\Messaging\Console\Commands\SyncMessageTemplatePresetsCommand;
use App\Modules\Messaging\Deployment\MessagingDeploymentPlanContributor;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Jobs\ProcessDueMessageChainEnrollmentsJob;
use App\Modules\Messaging\Jobs\PruneScheduledMessageCtaEngagementsJob;
use App\Modules\Messaging\Jobs\PublishScheduledMessageOutboxEventsJob;
use App\Modules\Messaging\Import\ServiceRelationshipPermissionContactImportPostProcessor;
use App\Modules\Messaging\Jobs\RecoverStaleScheduledMessageClaimsJob;
use App\Modules\Messaging\Listeners\AdvanceMessageChainEnrollmentAfterScheduledMessageTerminal;
use App\Modules\Messaging\Listeners\GrantManualContactCreatedConsents;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationFailedAfterScheduledMessageFailed;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkipped;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationSentAfterScheduledMessageSent;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPanels\ContactDirectMessagePanelProvider;
use App\Modules\Messaging\Services\ContactPanels\MessageDeliveryIssueContactPanelProvider;
use App\Modules\Messaging\Services\ContactShow\ContactMessagingShowDataProvider;
use App\Modules\Messaging\Services\ContactShow\ContactScheduledMessagesVisibilityDataProvider;
use App\Modules\Messaging\Services\Dashboard\MessagingDeliveryIssuesDashboardPanelProvider;
use App\Modules\Messaging\Services\Email\EmailProviderManager;
use App\Modules\Messaging\Services\MessageChainExecutionContextResolver;
use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use App\Modules\Messaging\Services\MessageRecipientGateRegistry;
use App\Modules\Messaging\Services\MessageRecipientPayloadProviderRegistry;
use App\Modules\Messaging\Services\MessageTemplateDefinitionRegistry;
use App\Modules\Messaging\Services\MessageTemplatePublicationHookRegistry;
use App\Modules\Messaging\Services\ReplyProfiles\MessagingReplyProfileDependencyContributor;
use App\Modules\Messaging\Services\ReusableMessageTemplateAuthoringGuide;
use App\Modules\Messaging\Services\Sms\SmsProviderManager;
use App\Modules\Messaging\TokenContracts\MessagingTokenContextProvider;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use App\Modules\Messaging\View\Components\MessageMediaAuthoring;
use App\Support\Dashboard\DashboardPanelRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Twilio\Rest\Client;

class MessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MessageMediaAuthoringService::class);

        $this->mergeConfigFrom(config_path('messaging/sms.php'), 'messaging.sms');
        $this->mergeConfigFrom(config_path('messaging/email.php'), 'messaging.email');

        $this->app->tag([
            EmailMessageDefinitionConfigContract::class,
            SmsMessageDefinitionConfigContract::class,
            PermissionInvitationConfigContract::class,
        ], 'config.contracts');

        $this->app->tag(
            MessagingConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );

        $this->app->tag(
            MessagingTokenContextProvider::class,
            'token.context_providers',
        );

        $this->app->tag([
            MessagingAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            MessagingAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            MessagingAutomationPointAuthoringContributor::class,
        ], 'automation.point_authoring_contributors');

        $this->app->tag([
            SendMessageAutomationActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->tag([
            MessagingSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $this->app->tag(
            MessagingDeploymentPlanContributor::class,
            'deployment.plan_contributors',
        );

        $this->app->tag([
            MessagingReplyProfileDependencyContributor::class,
        ], ReplyProfileDependencyRegistry::CONTRIBUTOR_TAG);

        $this->app->tag([
            MessagingDeliveryIssuesDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());

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

        $this->app->singleton(MessageRecipientPayloadProviderRegistry::class, function ($app) {
            return new MessageRecipientPayloadProviderRegistry(
                providers: $app->tagged('messaging.message_recipient_payload_providers'),
            );
        });

        $this->app->singleton(MessageChainExecutionContextResolver::class, function ($app) {
            return new MessageChainExecutionContextResolver(
                providers: $app->tagged(
                    'messaging.message_chain_execution_context_providers',
                ),
            );
        });

        $this->app->singleton(MessageTemplateDefinitionRegistry::class, function ($app) {
            $moduleManager = $app->make(ModuleManager::class);
            $contributors = [];

            foreach ($moduleManager->messageTemplateDefinitionContributorClasses() as $contributorClass) {
                if (! class_exists($contributorClass)) {
                    throw new InvalidArgumentException(
                        "Configured message template definition contributor class [{$contributorClass}] does not exist."
                    );
                }

                $contributor = $app->make($contributorClass);

                if (! $contributor instanceof MessageTemplateDefinitionContributor) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured message template definition contributor [%s] must implement [%s].',
                        $contributorClass,
                        MessageTemplateDefinitionContributor::class,
                    ));
                }

                $contributors[] = $contributor;
            }

            return new MessageTemplateDefinitionRegistry(
                contributors: $contributors,
                moduleManager: $moduleManager,
            );
        });

        $this->app->singleton(MessageTemplatePublicationHookRegistry::class, function ($app) {
            return new MessageTemplatePublicationHookRegistry(
                hooks: $app->tagged('messaging.message_template_publication_hooks'),
            );
        });

        $this->app->singleton(ReusableMessageTemplateAuthoringGuide::class, function ($app) {
            return new ReusableMessageTemplateAuthoringGuide(
                contributors: $app->tagged(ReusableMessageTemplateAuthoringOptionContributor::TAG),
            );
        });

        $this->app->tag([
            ContactMessagingShowDataProvider::class,
            ContactScheduledMessagesVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        Blade::component('messaging.message-media-authoring', MessageMediaAuthoring::class);

        $this->app->make(ContactImportPostProcessorRegistry::class)
            ->registerProcessor(ServiceRelationshipPermissionContactImportPostProcessor::class);

        $this->app->make(ContactPanelRegistry::class)
            ->register(ContactDirectMessagePanelProvider::class, 'messaging')
            ->register(MessageDeliveryIssueContactPanelProvider::class, 'messaging');

        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                $schedule
                    ->job(new RecoverStaleScheduledMessageClaimsJob())
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule
                    ->job(new ProcessDueMessageChainEnrollmentsJob())
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule
                    ->job(new PublishScheduledMessageOutboxEventsJob())
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule
                    ->job(new PruneScheduledMessageCtaEngagementsJob())
                    ->daily()
                    ->withoutOverlapping();
            },
        );

        Event::listen(
            ManualContactCreated::class,
            GrantManualContactCreatedConsents::class,
        );

        Event::listen(
            ScheduledMessageSent::class,
            MarkClaimedPermissionInvitationSentAfterScheduledMessageSent::class,
        );
        Event::listen(
            ScheduledMessageSent::class,
            AdvanceMessageChainEnrollmentAfterScheduledMessageTerminal::class,
        );

        Event::listen(
            ScheduledMessageSkipped::class,
            MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkipped::class,
        );
        Event::listen(
            ScheduledMessageSkipped::class,
            AdvanceMessageChainEnrollmentAfterScheduledMessageTerminal::class,
        );

        Event::listen(
            ScheduledMessageFailed::class,
            MarkClaimedPermissionInvitationFailedAfterScheduledMessageFailed::class,
        );
        Event::listen(
            ScheduledMessageFailed::class,
            AdvanceMessageChainEnrollmentAfterScheduledMessageTerminal::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditEmailHygieneCommand::class,
                SyncMessageTemplatePresetsCommand::class,
            ]);
        }

        Contact::deleting(function (Contact $contact): void {
            app(CancelContactMessagingRuntimeAction::class)->handle($contact);
        });

        Contact::resolveRelationUsing('messageConsents', function (Contact $contact): HasMany {
            return $contact->hasMany(MessageConsent::class);
        });

        Contact::resolveRelationUsing('permissionInvitations', function (Contact $contact): HasMany {
            return $contact->hasMany(ContactPermissionInvitation::class);
        });

        Contact::resolveRelationUsing('scheduledMessages', function (Contact $contact): HasMany {
            return $contact->hasMany(ScheduledMessage::class, 'recipient_id')
                ->where('recipient_type', $contact->getMorphClass());
        });
    }
}