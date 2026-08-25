<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Automation\AddContactTagAutomationActionHandler;
use App\Modules\Core\Automation\ContactTagAutomationPointAuthoringContributor;
use App\Modules\Core\Automation\ContactTagAutomationPointDefinitionContributor;
use App\Modules\Core\Automation\RemoveContactTagAutomationActionHandler;
use App\Modules\Core\Capabilities\CoreAutomationCapabilityContributor;
use App\Modules\Core\ConfigContracts\ContactStatusConfigContractTargetProvider;
use App\Modules\Core\ConfigContracts\ContactStatusDefinitionConfigContract;
use App\Modules\Core\Console\Commands\SyncContactStatusPresetsCommand;
use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Core\Import\Treatments\ContactStatusImportTreatmentTarget;
use App\Modules\Core\Import\Treatments\ContactTagsImportTreatmentTarget;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Observers\ContactEligibilityFactObserver;
use App\Modules\Core\Observers\ContactTagEligibilityFactObserver;
use App\Modules\Core\Services\Contacts\Filters\ImportBatchContactFilterCriterion;
use App\Modules\Core\Services\Contacts\Filters\SourceContactFilterCriterion;
use App\Modules\Core\Services\Contacts\Filters\SubsourceContactFilterCriterion;
use App\Modules\Core\Services\Contacts\Filters\TagContactFilterCriterion;
use App\Modules\Core\Services\ProcessHighway\CoreProcessHighwayEntryRampContributor;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Core\Support\Contacts\ContactShowDataRegistry;
use App\Modules\Core\TokenContracts\ContactTokenSourceProvider;
use App\Modules\Core\TokenContracts\SiteSettingTokenSourceProvider;
use App\Modules\Core\Validation\CoreSetupValidationContributor;
use App\Support\ProcessHighway\ProcessHighwayEntryRampInspector;
use Illuminate\Support\ServiceProvider;

class CoreModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContactPanelRegistry::class);
        $this->app->singleton(ContactImportTreatmentRegistry::class);
        $this->app->singleton(ContactImportPostProcessorRegistry::class);

        $this->app->tag([
            SourceContactFilterCriterion::class,
            SubsourceContactFilterCriterion::class,
            TagContactFilterCriterion::class,
            ImportBatchContactFilterCriterion::class,
        ], 'core.contact_filter_criteria');

        $this->app->singleton(ContactFilterCriterionRegistry::class, function ($app): ContactFilterCriterionRegistry {
            return new ContactFilterCriterionRegistry(
                criteria: $app->tagged('core.contact_filter_criteria'),
            );
        });

        $this->app->tag([
            CoreAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            ContactTagAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            ContactTagAutomationPointAuthoringContributor::class,
        ], 'automation.point_authoring_contributors');

        $this->app->tag([
            AddContactTagAutomationActionHandler::class,
            RemoveContactTagAutomationActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->tag([
            CoreProcessHighwayEntryRampContributor::class,
        ], ProcessHighwayEntryRampInspector::CONTRIBUTOR_TAG);

        $this->app->singleton(ContactShowDataRegistry::class, function ($app): ContactShowDataRegistry {
            return new ContactShowDataRegistry(
                providers: $app->tagged('core.contact_show_data_providers'),
            );
        });

        $this->app->singleton(ContactImportRegistry::class, function (): ContactImportRegistry {
            return (new ContactImportRegistry)->registerFields([
                ContactImportField::make(
                    key: 'first_name',
                    label: 'First Name',
                    contactAttribute: 'first_name',
                    sort: 10,
                ),

                ContactImportField::make(
                    key: 'last_name',
                    label: 'Last Name',
                    contactAttribute: 'last_name',
                    sort: 20,
                ),

                ContactImportField::make(
                    key: 'name',
                    label: 'Full Name',
                    contactAttribute: 'name',
                    sort: 30,
                ),

                ContactImportField::make(
                    key: 'email',
                    label: 'Email',
                    required: true,
                    contactAttribute: 'email',
                    sort: 40,
                ),

                ContactImportField::make(
                    key: 'phone',
                    label: 'Phone',
                    contactAttribute: 'phone',
                    sort: 50,
                ),

                ContactImportField::make(
                    key: 'birthday',
                    label: 'Birthday',
                    contactAttribute: 'birthday',
                    description: 'Birthday date used by recurring annual Campaign touches.',
                    sort: 55,
                ),

                ContactImportField::make(
                    key: 'source',
                    label: 'Source',
                    contactAttribute: 'source',
                    sort: 60,
                ),

                ContactImportField::make(
                    key: 'subsource',
                    label: 'Subsource',
                    contactAttribute: 'subsource',
                    sort: 70,
                ),

                ContactImportField::make(
                    key: 'last_contacted_at',
                    label: 'Last Contacted At',
                    contactAttribute: 'last_contacted_at',
                    sort: 80,
                ),

                ContactImportField::make(
                    key: 'last_activity_at',
                    label: 'Last Activity At',
                    contactAttribute: 'last_activity_at',
                    sort: 90,
                ),

                ContactImportField::make(
                    key: 'import_status',
                    label: 'Original Import Status',
                    section: 'Import Metadata',
                    description: 'Original status value from the imported system. Stored for audit/debugging only.',
                    sort: 1000,
                ),
            ]);
        });

        $this->app->tag(
            CoreSetupValidationContributor::class,
            'setup.validation_contributors',
        );

        $this->app->tag(
            ContactStatusDefinitionConfigContract::class,
            'config.contracts',
        );

        $this->app->tag(
            ContactStatusConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );

        $this->app->tag(
            ContactTokenSourceProvider::class,
            'token.source_providers',
        );

        $this->app->tag(
            SiteSettingTokenSourceProvider::class,
            'token.source_providers',
        );
    }

    public function boot(ContactImportTreatmentRegistry $treatments): void
    {
        Contact::observe(ContactEligibilityFactObserver::class);
        ContactTag::observe(ContactTagEligibilityFactObserver::class);

        $treatments
            ->registerTarget(ContactStatusImportTreatmentTarget::class)
            ->registerTarget(ContactTagsImportTreatmentTarget::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncContactStatusPresetsCommand::class,
            ]);
        }
    }
}