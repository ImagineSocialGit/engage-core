<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateContractRegistry;
use App\Support\ProjectState\ProjectStateDeferredReferenceApplier;
use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateDocumentValidator;
use App\Support\ProjectState\ProjectStateExporter;
use App\Support\ProjectState\ProjectStateImporter;
use App\Support\ProjectState\ProjectStateImportIdMap;
use App\Support\ProjectState\ProjectStateImportRowTransformer;
use App\Support\ProjectState\ProjectStateImportValueMapper;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateReferenceResolver;
use App\Support\ProjectState\ProjectStateResumeItemRecorder;
use App\Support\ProjectState\ProjectStateResumeManager;
use App\Support\ProjectState\ProjectStateSchemaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class ProjectStateFinalArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_all_project_state_collaborators_are_auto_resolvable(): void
    {
        $classes = [
            ProjectStateManager::class,
            ProjectStateExporter::class,
            ProjectStateImporter::class,
            ProjectStateDocumentCodec::class,
            ProjectStateDocumentValidator::class,
            ProjectStateContractRegistry::class,
            ProjectStateSchemaGuard::class,
            ProjectStateReferenceResolver::class,
            ProjectStateImportIdMap::class,
            ProjectStateImportRowTransformer::class,
            ProjectStateImportValueMapper::class,
            ProjectStateDeferredReferenceApplier::class,
            ProjectStateResumeItemRecorder::class,
            ProjectStateResumeManager::class,
        ];

        foreach ($classes as $class) {
            $this->assertInstanceOf($class, app($class));
        }
    }

    public function test_manager_remains_the_five_operation_facade(): void
    {
        $methods = collect((new ReflectionClass(ProjectStateManager::class))->getMethods())
            ->filter(fn ($method): bool => $method->isPublic() && ! $method->isConstructor())
            ->map(fn ($method): string => $method->getName())
            ->sort()
            ->values()
            ->all();

        $this->assertEquals([
            'decode',
            'encode',
            'export',
            'import',
            'validate',
        ], $methods);
    }

    public function test_contract_registry_rejects_duplicate_table_ownership(): void
    {
        $sections = config('project_state.sections');
        $sections['duplicate_core'] = [
            'version' => 1,
            'tables' => [
                'contacts' => $sections['core']['tables']['contacts'],
            ],
        ];
        config()->set('project_state.sections', $sections);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state table [contacts] is configured in both [core] and [duplicate_core].'
        );

        app(ProjectStateContractRegistry::class)->sections();
    }

    public function test_contract_registry_rejects_unknown_resume_categories(): void
    {
        $sections = config('project_state.sections');
        $sections['messaging']['tables']['scheduled_messages']['resume_items'][0]['category'] = 'unknown_runtime_work';
        config()->set('project_state.sections', $sections);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state table [scheduled_messages] resume item uses unsupported category [unknown_runtime_work].'
        );

        app(ProjectStateContractRegistry::class)->sections();
    }

    public function test_every_configured_resume_item_uses_a_supported_category(): void
    {
        $configuredCategories = collect(config('project_state.sections'))
            ->flatMap(fn (array $section) => $section['tables'])
            ->flatMap(fn (array $definition): array => $definition['resume_items'] ?? [])
            ->pluck('category')
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            $configuredCategories,
            ProjectStateResumeManager::supportedCategoryKeys(),
        );
    }

    public function test_validator_rejects_table_names_repeated_across_sections(): void
    {
        $manager = app(ProjectStateManager::class);
        $document = $manager->export();
        $document['sections']['shadow'] = [
            'version' => 1,
            'tables' => [
                'contacts' => [],
            ],
        ];
        $document['checksum'] = app(ProjectStateDocumentCodec::class)->checksum($document);

        $report = $manager->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Project-state table [contacts] appears in multiple sections: [core], [shadow].',
            $report['errors'],
        );
    }

    public function test_contract_registry_skips_optional_sections_when_activation_schema_is_absent(): void
    {
        $sections = config('project_state.sections');
        $sections['future_optional'] = [
            'version' => 1,
            'optional' => true,
            'activation_tables' => [
                'future_optional_metrics',
            ],
            'tables' => [
                'future_optional_metrics' => $sections['reporting']['tables']['reporting_daily_metrics'],
            ],
        ];
        config()->set('project_state.sections', $sections);

        $registry = app(ProjectStateContractRegistry::class);

        $this->assertArrayHasKey(
            'future_optional',
            $registry->configuredSections(),
        );
        $this->assertArrayNotHasKey(
            'future_optional',
            $registry->sections(),
        );
    }

    public function test_contract_registry_rejects_partially_installed_optional_section_schema(): void
    {
        $sections = config('project_state.sections');
        $sections['future_optional'] = [
            'version' => 1,
            'optional' => true,
            'activation_tables' => [
                'contacts',
                'future_optional_metrics',
            ],
            'tables' => [
                'future_optional_metrics' => $sections['reporting']['tables']['reporting_daily_metrics'],
            ],
        ];
        config()->set('project_state.sections', $sections);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Optional project-state section [future_optional] has a partially installed activation schema. Missing table(s): future_optional_metrics.'
        );

        app(ProjectStateContractRegistry::class)->sections();
    }

    public function test_validator_rejects_known_optional_section_when_target_activation_schema_is_absent(): void
    {
        $sections = config('project_state.sections');
        $sections['future_optional'] = [
            'version' => 1,
            'optional' => true,
            'activation_tables' => [
                'future_optional_metrics',
            ],
            'tables' => [
                'future_optional_metrics' => $sections['reporting']['tables']['reporting_daily_metrics'],
            ],
        ];
        config()->set('project_state.sections', $sections);

        $manager = app(ProjectStateManager::class);
        $document = $manager->export();
        $document['sections']['future_optional'] = [
            'version' => 1,
            'tables' => [
                'future_optional_metrics' => [],
            ],
        ];
        $document['checksum'] = app(ProjectStateDocumentCodec::class)->checksum($document);

        $report = $manager->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertContains(
            'Optional project-state section [future_optional] is present in the document but its activation schema is not installed on the target.',
            $report['errors'],
        );
    }

    public function test_contract_registry_rejects_malformed_optional_section_configuration(): void
    {
        $sections = config('project_state.sections');
        $sections['future_optional'] = [
            'version' => 1,
            'optional' => null,
            'tables' => [
                'future_optional_metrics' => $sections['reporting']['tables']['reporting_daily_metrics'],
            ],
        ];
        config()->set('project_state.sections', $sections);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state section configuration is invalid.'
        );

        app(ProjectStateContractRegistry::class)->configuredSections();
    }

}