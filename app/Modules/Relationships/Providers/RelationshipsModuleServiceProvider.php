<?php

namespace App\Modules\Relationships\Providers;

use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use App\Modules\Relationships\Import\ContactRelationshipImportHandler;
use App\Modules\Relationships\Import\Treatments\RelationshipStageImportTreatmentTarget;
use App\Modules\Relationships\Import\Treatments\RelationshipTypeImportTreatmentTarget;
use App\Modules\Relationships\Services\Contacts\Filters\RelationshipContactFilterCriterion;
use App\Modules\Relationships\Validation\RelationshipsSetupValidationContributor;
use Illuminate\Support\ServiceProvider;

class RelationshipsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            RelationshipsSetupValidationContributor::class,
            'setup.validation_contributors',
        );

        $this->app->tag([
            RelationshipContactFilterCriterion::class,
        ], 'core.contact_filter_criteria');
    }

    public function boot(
        ContactImportRegistry $contactImports,
        ContactImportTreatmentRegistry $treatments,
    ): void {
        $treatments
            ->registerTarget(RelationshipTypeImportTreatmentTarget::class)
            ->registerTarget(RelationshipStageImportTreatmentTarget::class);

        $contactImports
            ->registerFields([
                ContactImportField::make(
                    key: 'relationship_key',
                    label: 'Relationship Type Key',
                    section: 'Relationship',
                    description: 'Configured relationship key such as consumer or realtor.',
                    sort: 2000,
                ),
                ContactImportField::make(
                    key: 'relationship_stage',
                    label: 'Relationship Stage Key',
                    section: 'Relationship',
                    description: 'Configured stage key for the selected relationship.',
                    sort: 2010,
                ),
                ContactImportField::make(
                    key: 'relationship_source',
                    label: 'Relationship Source',
                    section: 'Relationship',
                    description: 'Relationship-specific acquisition source. Falls back to the imported Contact source evidence.',
                    sort: 2020,
                ),
                ContactImportField::make(
                    key: 'relationship_subsource',
                    label: 'Relationship Subsource',
                    section: 'Relationship',
                    sort: 2030,
                ),
                ContactImportField::make(
                    key: 'relationship_started_at',
                    label: 'Relationship Started At',
                    section: 'Relationship',
                    sort: 2040,
                ),
            ])
            ->registerHandler(ContactRelationshipImportHandler::class);
    }
}