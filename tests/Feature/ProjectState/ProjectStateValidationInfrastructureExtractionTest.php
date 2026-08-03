<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateDocumentValidator;
use App\Support\ProjectState\ProjectStateImportValueMapper;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectStateValidationInfrastructureExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_manager_validation_preserves_the_extracted_validator_report(): void
    {
        $manager = app(ProjectStateManager::class);
        $document = $manager->export();

        $this->assertEquals(
            app(ProjectStateDocumentValidator::class)->validate($document),
            $manager->validate($document),
        );
    }

    public function test_reference_resolver_builds_a_stateless_document_table_index(): void
    {
        $resolver = app(ProjectStateReferenceResolver::class);
        $documentTables = $resolver->documentTables([
            'sections' => [
                'core' => [
                    'tables' => [
                        'contacts' => [
                            ['id' => 40, 'name' => 'Source Contact'],
                        ],
                        'ignored_non_array_rows' => 'invalid',
                    ],
                ],
                'tasks' => [
                    'tables' => [
                        'tasks' => [
                            ['id' => '50', 'title' => 'Review contact'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertEquals([
            'contacts' => [
                ['id' => 40, 'name' => 'Source Contact'],
            ],
            'tasks' => [
                ['id' => '50', 'title' => 'Review contact'],
            ],
        ], $documentTables);
        $this->assertTrue($resolver->documentTableContainsId(
            documentTables: $documentTables,
            table: 'contacts',
            sourceId: '40',
        ));
        $this->assertTrue($resolver->documentTableContainsId(
            documentTables: $documentTables,
            table: 'tasks',
            sourceId: 50,
        ));
        $this->assertFalse($resolver->documentTableContainsId(
            documentTables: $documentTables,
            table: 'contacts',
            sourceId: 999,
        ));
        $this->assertNull($resolver->documentTableRows(
            documentTables: $documentTables,
            table: 'missing_table',
        ));
    }

    public function test_import_value_mapper_preserves_scalar_and_boolean_mapping_rules(): void
    {
        $mapper = app(ProjectStateImportValueMapper::class);

        $this->assertSame('paused', $mapper->map('pending', [
            'pending' => 'paused',
        ]));
        $this->assertSame('enabled', $mapper->map(true, [
            '1' => 'enabled',
            '0' => 'disabled',
        ]));
        $this->assertSame('disabled', $mapper->map(false, [
            '1' => 'enabled',
            '0' => 'disabled',
        ]));
        $this->assertNull($mapper->map(null, [
            '' => 'changed',
        ]));
        $this->assertSame('[true]', $mapper->display(true));
        $this->assertSame('[null]', $mapper->display(null));
        $this->assertSame('[pending]', $mapper->display('pending'));
    }
}