<?php

namespace App\Providers;

use App\Console\Commands\EngageDeploymentPlanCommand;
use App\Console\Commands\EngageEnvironmentSyncCommand;
use App\Console\Commands\SyncPresetsCommand;
use App\Console\Commands\ValidateSetupCommand;
use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Campaigns\Import\CampaignEnrollmentContactImportPostProcessor;
use App\Modules\Campaigns\Import\CampaignLaunchTimingContactImportPostProcessor;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Messaging\Import\MarketingPermissionContactImportPostProcessor;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use App\Support\ModuleIntegrations\Forms\Messaging\GrantFormSubmissionMessagingConsent;
use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use App\Support\DestinationVerification\UnavailableDestinationVerificationTransport;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use App\Support\ModuleIntegrations\Scheduling\UnavailableAppointmentCommunications;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingAppointmentCommunications;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingSchedulingDestinationVerificationTransport;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentMessageChainExecutionContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenSourceProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTemplatePublicationHook;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingDestinationVerificationRecipientGate;
use App\Support\ModuleIntegrations\InternalNotifications\InboundMessaging\ScheduleInboundMessageInternalNotification;
use App\Support\ModuleIntegrations\InternalNotifications\Tasks\InternalNotificationTaskScheduler;
use App\Support\ModuleIntegrations\InternalNotifications\Tasks\OnlyActiveTeamMemberTaskAssignmentStrategyResolver;
use App\Support\ModuleIntegrations\InternalNotifications\Tasks\TeamMemberTaskAssignedRecipientResolver;
use App\Support\ModuleIntegrations\InternalNotifications\Tasks\TeamMemberTaskAssigneeOptionProvider;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Jobs\PublishAutomationEventOutboxEventsJob;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\ConfigContractTargetRegistry;
use App\Support\ConfigContracts\Contracts\ModuleDefinitionConfigContract;
use App\Support\ConfigContracts\Contracts\PresetPackageConfigContract;
use App\Support\ConfigContracts\TargetProviders\AppConfigContractTargetProvider;
use App\Support\ModuleIntegrations\RelationshipLocationAreaImportHandler;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Deployment\EnvironmentFileSynchronizer;
use App\Support\Modules\ModuleManager;
use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use App\Support\Reporting\ReportingEventDefinitionRegistry;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use App\Support\Reporting\Services\NoopReportingObservationRecorder;
use App\Support\SetupValidation\Contributors\ConfigContractsSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ModuleDependenciesSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ModuleMigrationsSetupValidationContributor;
use App\Support\SetupValidation\Contributors\PresetCompositionSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ReferenceRegistrySetupValidationContributor;
use App\Support\ModuleFacts\Validation\ModuleFactsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(EnvironmentFileRepository::class);
        $this->app->singleton(EnvironmentFileSynchronizer::class);
        $this->app->singleton(DeploymentPlanResolver::class, function ($app): DeploymentPlanResolver {
            return new DeploymentPlanResolver(
                contributors: $app->tagged('deployment.plan_contributors'),
                environmentFiles: $app->make(EnvironmentFileRepository::class),
                modules: $app->make(ModuleManager::class),
            );
        });

        $this->app->singleton(
            FormSubmissionConsentBridge::class,
            function ($app): FormSubmissionConsentBridge {
                $enabled = $app->make(ModuleManager::class)
                    ->enabledKeysWithDependencies();
                $handlers = [];

                if (in_array('forms', $enabled, true)
                    && in_array('messaging', $enabled, true)
                ) {
                    $handlers[] = $app->make(
                        GrantFormSubmissionMessagingConsent::class,
                    );
                }

                return new FormSubmissionConsentBridge($handlers);
            },
        );

        $this->app->singleton(
            DestinationVerificationTransport::class,
            function ($app): DestinationVerificationTransport {
                $enabled = $app->make(ModuleManager::class)
                    ->enabledKeysWithDependencies();

                if (in_array('scheduling', $enabled, true)
                    && in_array('messaging', $enabled, true)
                ) {
                    return $app->make(
                        MessagingSchedulingDestinationVerificationTransport::class,
                    );
                }

                return $app->make(
                    UnavailableDestinationVerificationTransport::class,
                );
            },
        );

        $this->app->singleton(
            AppointmentCommunications::class,
            function ($app): AppointmentCommunications {
                $enabled = $app->make(ModuleManager::class)
                    ->enabledKeysWithDependencies();

                if (in_array('scheduling', $enabled, true)
                    && in_array('messaging', $enabled, true)
                ) {
                    return $app->make(MessagingAppointmentCommunications::class);
                }

                return $app->make(UnavailableAppointmentCommunications::class);
            },
        );

        $this->app->singleton(AutomationCapabilityRegistry::class, function ($app): AutomationCapabilityRegistry {
            return new AutomationCapabilityRegistry(
                contributors: $app->tagged('automation.capability_contributors'),
            );
        });

        $this->app->singleton(AutomationPointDefinitionRegistry::class, function ($app): AutomationPointDefinitionRegistry {
            return new AutomationPointDefinitionRegistry(
                contributors: $app->tagged('automation.point_definition_contributors'),
            );
        });

        $this->app->singleton(AutomationActionRegistry::class, function ($app): AutomationActionRegistry {
            return new AutomationActionRegistry(
                handlers: $app->tagged('automation.action_handlers'),
            );
        });

        $this->app->singleton(
            ReportingObservationRecorder::class,
            NoopReportingObservationRecorder::class,
        );

        $this->app->singleton(ReportingEventDefinitionRegistry::class, function ($app): ReportingEventDefinitionRegistry {
            return new ReportingEventDefinitionRegistry(
                contributors: $app->tagged('reporting.event_definition_contributors'),
            );
        });

        $this->app->singleton(ReportingProjectionFactRegistry::class, function ($app): ReportingProjectionFactRegistry {
            return new ReportingProjectionFactRegistry(
                contributors: $app->tagged('reporting.projection_fact_contributors'),
            );
        });

        $this->app->singleton(ConfigContractRegistry::class, function ($app): ConfigContractRegistry {
            return new ConfigContractRegistry(
                contracts: $app->tagged('config.contracts'),
            );
        });

        $this->app->tag([
            ModuleDefinitionConfigContract::class,
            PresetPackageConfigContract::class,
        ], 'config.contracts');

        $this->app->tag(
            AppConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );

        $this->app->singleton(ConfigContractTargetRegistry::class, function ($app): ConfigContractTargetRegistry {
            return new ConfigContractTargetRegistry(
                contracts: $app->make(ConfigContractRegistry::class),
                providers: $app->tagged('config.contract_target_providers'),
            );
        });

        $this->app->singleton(TokenContractRegistry::class, function ($app): TokenContractRegistry {
            return new TokenContractRegistry(
                sourceProviders: $app->tagged('token.source_providers'),
                contextProviders: $app->tagged('token.context_providers'),
            );
        });

        $this->app->singleton(PresetContributionRegistry::class, function ($app): PresetContributionRegistry {
            $contributors = [];

            foreach ($app->make(ModuleManager::class)->presetContributorClasses() as $contributorClass) {
                if (! class_exists($contributorClass)) {
                    throw new InvalidArgumentException(
                        "Configured preset contributor class [{$contributorClass}] does not exist."
                    );
                }

                $contributor = $app->make($contributorClass);

                if (! $contributor instanceof PresetContributor) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured preset contributor [%s] must implement [%s].',
                        $contributorClass,
                        PresetContributor::class,
                    ));
                }

                $contributors[] = $contributor;
            }

            return new PresetContributionRegistry($contributors);
        });

        $this->app->singleton(PresetPackageResolver::class);
        $this->app->singleton(PresetCompositionResolver::class);

        $this->app->singleton(SetupValidationManager::class, function ($app): SetupValidationManager {
            return new SetupValidationManager(
                contributors: $app->tagged('setup.validation_contributors'),
            );
        });

        $this->app->tag([
            ConfigContractsSetupValidationContributor::class,
            ModuleDependenciesSetupValidationContributor::class,
            ModuleMigrationsSetupValidationContributor::class,
            PresetCompositionSetupValidationContributor::class,
            ReferenceRegistrySetupValidationContributor::class,
            ModuleFactsSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $enabledModules = $this->app->make(ModuleManager::class)->enabledKeysWithDependencies();

        if (in_array('scheduling', $enabledModules, true)
            && in_array('messaging', $enabledModules, true)
        ) {
            $this->app->tag(
                SchedulingDestinationVerificationRecipientGate::class,
                'messaging.message_recipient_gates',
            );

            $this->app->tag(
                SchedulingAppointmentMessageChainExecutionContextProvider::class,
                'messaging.message_chain_execution_context_providers',
            );

            $this->app->tag(
                SchedulingAppointmentTokenSourceProvider::class,
                'token.source_providers',
            );

            $this->app->tag(
                SchedulingAppointmentTokenContextProvider::class,
                'token.context_providers',
            );

            $this->app->tag(
                SchedulingAppointmentTemplatePublicationHook::class,
                'messaging.message_template_publication_hooks',
            );
        }

        if (in_array('internal_notifications', $enabledModules, true)
            && in_array('tasks', $enabledModules, true)
        ) {
            $this->app->tag([
                OnlyActiveTeamMemberTaskAssignmentStrategyResolver::class,
            ], 'tasks.assignment_strategy_resolvers');

            $this->app->tag([
                TeamMemberTaskAssignedRecipientResolver::class,
            ], 'crm.tasks.assigned_recipient_resolvers');

            $this->app->tag([
                TeamMemberTaskAssigneeOptionProvider::class,
            ], 'tasks.assignee_option_providers');

            $this->app->tag([
                InternalNotificationTaskScheduler::class,
            ], 'tasks.notification_schedulers');
        }

        $this->app->afterResolving(
            ContactImportRegistry::class,
            function (ContactImportRegistry $registry, $app): void {
                $modules = $app->make(ModuleManager::class);

                if (! $modules->enabled('location')
                    || ! in_array('relationships', $modules->enabledKeysWithDependencies(), true)
                ) {
                    return;
                }

                $registry
                    ->registerFields([
                        ContactImportField::make(
                            key: 'relationship_location_area_key',
                            label: 'Relationship Location Area Key',
                            section: 'Relationship — Location',
                            description: 'Existing active LocationArea key used for this relationship context.',
                            sort: 2100,
                        ),
                        ContactImportField::make(
                            key: 'relationship_location_area_primary',
                            label: 'Primary Relationship Area?',
                            section: 'Relationship — Location',
                            description: 'Optional Yes/No value. Defaults to Yes when an area key is imported.',
                            sort: 2110,
                        ),
                    ])
                    ->registerHandler(RelationshipLocationAreaImportHandler::class);
            },
        );

        $this->app->afterResolving(
            ContactImportPostProcessorRegistry::class,
            function (ContactImportPostProcessorRegistry $registry, $app): void {
                $modules = $app->make(ModuleManager::class);
                $enabled = $modules->enabledKeysWithDependencies();

                if (in_array('messaging', $enabled, true)) {
                    $registry->registerProcessor(
                        MarketingPermissionContactImportPostProcessor::class,
                    );
                }

                if (in_array('campaigns', $enabled, true)) {
                    $registry
                        ->registerProcessor(
                            CampaignEnrollmentContactImportPostProcessor::class,
                        )
                        ->registerProcessor(
                            CampaignLaunchTimingContactImportPostProcessor::class,
                        );
                }
            },
        );
    }

    public function boot(): void
    {
        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                $schedule
                    ->job(new PublishAutomationEventOutboxEventsJob())
                    ->everyMinute()
                    ->withoutOverlapping();
            },
        );

        Event::listen(
            AutomationEventRecorded::class,
            RecordAutomationEventCorrelationEvidenceAction::class,
        );

        $enabledModules = $this->app->make(ModuleManager::class)->enabledKeysWithDependencies();

        if (in_array('internal_notifications', $enabledModules, true)
            && in_array('inbound_messaging', $enabledModules, true)
        ) {
            Event::listen(
                InboundMessageReceived::class,
                ScheduleInboundMessageInternalNotification::class,
            );
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                EngageDeploymentPlanCommand::class,
                EngageEnvironmentSyncCommand::class,
                SyncPresetsCommand::class,
                ValidateSetupCommand::class,
            ]);
        }
    }
}