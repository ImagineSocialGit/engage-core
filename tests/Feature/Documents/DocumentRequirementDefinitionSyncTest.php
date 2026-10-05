<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Actions\SyncDocumentRequirementDefinitionsAction;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Services\DocumentRequirementDefinitionRegistry;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentRequirementDefinitionContributor;
use App\Support\ModuleIntegrations\Documents\Data\DocumentRequirementDefinitionContribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DocumentRequirementDefinitionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributed_requirements_sync_idempotently_without_overwriting_manual_rows(): void
    {
        $this->app->tag(
            TestDocumentRequirementContributor::class,
            DocumentRequirementDefinitionContributor::TAG,
        );
        $this->app->forgetInstance(DocumentRequirementDefinitionRegistry::class);

        $action = app(SyncDocumentRequirementDefinitionsAction::class);

        $first = $action->handle();
        $second = $action->handle();

        $managed = DocumentRequirementDefinition::query()
            ->where('key', 'fixture_certificate')
            ->sole();

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame('contributor:documents_fixture', $managed->source);
        $this->assertTrue($managed->requires_review);
        $this->assertSame(['application/pdf'], $managed->accepted_mime_types);

        DocumentRequirementDefinition::query()->create([
            'key' => 'manual_certificate',
            'name' => 'Manual override',
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'source' => 'manual',
        ]);

        $this->app->tag(
            ManualCollisionDocumentRequirementContributor::class,
            DocumentRequirementDefinitionContributor::TAG,
        );
        $this->app->forgetInstance(DocumentRequirementDefinitionRegistry::class);

        $result = app(SyncDocumentRequirementDefinitionsAction::class)->handle();

        $manual = DocumentRequirementDefinition::query()
            ->where('key', 'manual_certificate')
            ->sole();

        $this->assertSame(1, $result['preserved']);
        $this->assertSame('Manual override', $manual->name);
        $this->assertSame('manual', $manual->source);
    }
}

final class TestDocumentRequirementContributor implements DocumentRequirementDefinitionContributor
{
    public function contributorKey(): string
    {
        return 'documents_fixture';
    }

    public function definitions(): iterable
    {
        yield new DocumentRequirementDefinitionContribution(
            key: 'fixture_certificate',
            name: 'Fixture certificate',
            category: DocumentRequirementDefinition::CATEGORY_GENERAL,
            requiresReview: true,
            acceptedMimeTypes: ['application/pdf'],
        );
    }
}

final class ManualCollisionDocumentRequirementContributor implements DocumentRequirementDefinitionContributor
{
    public function contributorKey(): string
    {
        return 'manual_collision_fixture';
    }

    public function definitions(): iterable
    {
        yield new DocumentRequirementDefinitionContribution(
            key: 'manual_certificate',
            name: 'Contributor version',
        );
    }
}