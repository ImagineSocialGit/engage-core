<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateContractRegistry;
use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateSchemaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProjectStateInfrastructureExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_manager_document_api_preserves_the_current_codec_contract(): void
    {
        $manager = app(ProjectStateManager::class);
        $codec = app(ProjectStateDocumentCodec::class);

        $document = $manager->export();
        $encoded = $manager->encode($document);

        $this->assertStringEndsWith(PHP_EOL, $encoded);
        $this->assertEquals($document, $manager->decode($encoded));
        $this->assertSame($document['checksum'], $codec->checksum($document));
    }

    public function test_contract_registry_exposes_the_normalized_version_eleven_contract(): void
    {
        $registry = app(ProjectStateContractRegistry::class);
        $sections = $registry->sections();

        $this->assertSame('engage-core-project-state', $registry->format());
        $this->assertSame(11, $registry->version());
        $this->assertEquals([
            'core',
            'internal_notifications',
            'inbound_messaging',
            'messaging',
            'webinars',
            'tasks',
            'campaigns',
            'broadcasts',
            'workflow',
            'automation_opportunities',
            'automation_events',
            'flow_routes',
            'reporting',
        ], array_keys($sections));
        $this->assertEquals(
            ['migrations', 'sqlite_sequence'],
            $registry->ignoredTables(),
        );
        $this->assertSame(
            'must_be_empty',
            $registry->tablePolicies()['appointments']['mode'],
        );
        $this->assertEquals(
            ['id'],
            $sections['inbound_messaging']['tables']['inbound_messages']['order_by'],
        );
    }

    public function test_manager_preserves_invalid_json_errors_after_codec_extraction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The uploaded project-state file is not valid JSON:'
        );

        app(ProjectStateManager::class)->decode('{');
    }

    public function test_schema_guard_preserves_duplicate_classification_errors(): void
    {
        config()->set('project_state.table_policies.contacts', [
            'mode' => 'resettable',
            'reason' => 'Test duplicate classification.',
        ]);

        $registry = app(ProjectStateContractRegistry::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state table policy duplicates exported table(s): contacts.'
        );

        app(ProjectStateSchemaGuard::class)->assertSchemaCoverage(
            $registry->sections(),
        );
    }
}