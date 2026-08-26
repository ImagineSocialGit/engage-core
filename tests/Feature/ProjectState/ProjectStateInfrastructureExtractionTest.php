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

    public function test_contract_registry_exposes_the_current_normalized_contract(): void
    {
        $registry = app(ProjectStateContractRegistry::class);
        $sections = $registry->sections();
        $configuredSections = $registry->configuredSections();

        $this->assertSame('engage-core-project-state', $registry->format());
        $this->assertSame(
            (int) config('project_state.version'),
            $registry->version(),
        );

        $this->assertNotEmpty($configuredSections);
        $this->assertNotEmpty($sections);

        foreach ($configuredSections as $sectionKey => $configuredSection) {
            if (($configuredSection['optional'] ?? false) !== true) {
                $this->assertArrayHasKey(
                    $sectionKey,
                    $sections,
                    "Required Project State section [{$sectionKey}] must be active.",
                );
            }

            if (! array_key_exists($sectionKey, $sections)) {
                continue;
            }

            $this->assertSame(
                (int) $configuredSection['version'],
                (int) $sections[$sectionKey]['version'],
            );

            $this->assertEquals(
                array_keys($configuredSection['tables']),
                array_keys($sections[$sectionKey]['tables']),
            );
        }

        foreach ($sections as $sectionKey => $section) {
            $this->assertArrayHasKey(
                $sectionKey,
                $configuredSections,
                "Active Project State section [{$sectionKey}] must come from the configured contract.",
            );
        }

        $this->assertEquals(
            ['migrations', 'sqlite_sequence'],
            $registry->ignoredTables(),
        );

        $this->assertSame(
            'must_be_empty',
            $registry->tablePolicies()['booking_holds']['mode'],
        );

        $this->assertEquals(
            ['id'],
            $sections['inbound_messaging']['tables']['inbound_messages']['order_by'],
        );
    }

    public function test_root_version_and_fingerprint_are_derived_from_the_section_contracts(): void
    {
        $registry = app(ProjectStateContractRegistry::class);
        $sectionVersions = $registry->sectionVersions();

        $this->assertNotEmpty($sectionVersions);
        $this->assertSame(array_sum($sectionVersions), $registry->version());
        $this->assertSame($registry->version(), (int) config('project_state.version'));
        $this->assertStringStartsWith('sha256:', $registry->contractFingerprint());
    }

    public function test_independent_section_version_bumps_compose_without_a_manual_root_bump(): void
    {
        $registry = app(ProjectStateContractRegistry::class);
        $beforeVersion = $registry->version();
        $beforeFingerprint = $registry->contractFingerprint();

        $sections = config('project_state.sections');
        $sections['campaigns']['version']++;
        $sections['messaging']['version']++;
        config()->set('project_state.sections', $sections);

        $this->assertSame($beforeVersion + 2, $registry->version());
        $this->assertNotSame($beforeFingerprint, $registry->contractFingerprint());
    }

    public function test_contract_fingerprint_changes_for_an_exact_contract_edit_even_without_a_section_version_bump(): void
    {
        $registry = app(ProjectStateContractRegistry::class);
        $beforeVersion = $registry->version();
        $beforeFingerprint = $registry->contractFingerprint();

        $sections = config('project_state.sections');
        $sections['core']['tables']['contacts']['order_by'] = ['id', 'email'];
        config()->set('project_state.sections', $sections);

        $this->assertSame($beforeVersion, $registry->version());
        $this->assertNotSame($beforeFingerprint, $registry->contractFingerprint());
    }

    public function test_validator_rejects_a_different_section_vector_even_when_the_root_sum_is_unchanged(): void
    {
        $manager = app(ProjectStateManager::class);
        $codec = app(ProjectStateDocumentCodec::class);
        $document = $manager->export();

        $campaignVersion = $document['contract']['section_versions']['campaigns'];
        $messagingVersion = $document['contract']['section_versions']['messaging'];

        $document['contract']['section_versions']['campaigns'] = $campaignVersion - 1;
        $document['contract']['section_versions']['messaging'] = $messagingVersion + 1;
        $document['version'] = array_sum($document['contract']['section_versions']);
        $document['checksum'] = $codec->checksum($document);

        $report = $manager->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertContains(
            sprintf(
                'Project-state contract section [campaigns] requires version [%d]; document has [%d].',
                $campaignVersion,
                $campaignVersion - 1,
            ),
            $report['errors'],
        );
        $this->assertContains(
            sprintf(
                'Project-state contract section [messaging] requires version [%d]; document has [%d].',
                $messagingVersion,
                $messagingVersion + 1,
            ),
            $report['errors'],
        );
        $this->assertContains(
            'The project-state section-version vector does not match the current contract.',
            $report['errors'],
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