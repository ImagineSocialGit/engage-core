<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\ContactImportOccurrence;
use Tests\TestCase;

class CampaignImportSourceReferenceContractTest extends TestCase
{
    public function test_campaign_enrollment_project_state_can_remap_contact_import_occurrence_sources(): void
    {
        $references = config(
            'project_state.sections.campaigns.tables.campaign_enrollments.polymorphic_references',
            [],
        );

        $targets = is_array($references[0]['targets'] ?? null)
            ? $references[0]['targets']
            : [];

        $this->assertSame(
            'contact_import_occurrences',
            $targets[ContactImportOccurrence::class] ?? null,
        );
    }
}