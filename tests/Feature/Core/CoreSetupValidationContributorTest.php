<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Validation\CoreSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CoreSetupValidationContributorTest extends TestCase
{
    public function test_it_accepts_valid_selected_contact_status_presets(): void
    {
        $this->setPresetPackage([
            'default',
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.default', [
            'new',
            'engaged',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions', [
            'new' => [
                'key' => 'new',
                'name' => 'New',
                'description' => 'New contact.',
                'category' => 'default',
                'meta' => [],
            ],
            'engaged' => [
                'key' => 'engaged',
                'name' => 'Engaged',
                'description' => 'Engaged contact.',
                'category' => 'default',
                'meta' => [
                    'intent_level' => 'medium',
                ],
            ],
        ]);

        $this->assertSame([], $this->findings());
    }



    public function test_it_reports_required_fields_key_mismatch_and_invalid_meta(): void
    {
        $this->setPresetPackage([
            'default',
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.default', [
            '__test_missing_key__',
            '__test_mismatched__',
            '__test_missing_name__',
            '__test_invalid_meta__',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions', [
            '__test_missing_key__' => [
                'name' => 'Missing key',
                'meta' => [],
            ],
            '__test_mismatched__' => [
                'key' => '__test_different_key__',
                'name' => 'Mismatched',
                'meta' => [],
            ],
            '__test_missing_name__' => [
                'key' => '__test_missing_name__',
                'meta' => [],
            ],
            '__test_invalid_meta__' => [
                'key' => '__test_invalid_meta__',
                'name' => 'Invalid meta',
                'meta' => 'not-an-array',
            ],
        ]);

        $findings = $this->findings();

        $this->assertSame([
            'core.contact_status.definition_key_missing',
            'core.contact_status.definition_key_mismatch',
            'core.contact_status.definition_name_missing',
            'core.contact_status.meta_invalid',
        ], array_column($findings, 'code'));
    }

    public function test_it_rejects_client_facing_nouns_in_internal_status_identifiers(): void
    {
        $this->setPresetPackage([
            'default',
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.default', [
            'new_lead',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions.new_lead', [
            'key' => 'new_lead',
            'name' => 'New Lead',
            'description' => 'Client-facing copy may use Lead.',
            'meta' => [],
        ]);

        $findings = $this->findings();

        $this->assertSame([
            'core.contact_status.noncanonical_identifier',
            'core.contact_status.noncanonical_identifier',
        ], array_column($findings, 'code'));

        $this->assertContains(
            'presets.modules.core.contact-statuses.definitions.new_lead',
            array_column($findings, 'path'),
        );

        $this->assertContains(
            'presets.modules.core.contact-statuses.definitions.new_lead.key',
            array_column($findings, 'path'),
        );
    }

    public function test_it_warns_when_first_class_fields_are_duplicated_inside_meta(): void
    {
        $this->setPresetPackage([
            'default',
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.default', [
            'engaged',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions.engaged', [
            'key' => 'engaged',
            'name' => 'Engaged',
            'description' => 'Engaged contact.',
            'category' => 'default',
            'color' => 'blue',
            'source_version' => '2026_07',
            'meta' => [
                'category' => 'default',
                'color' => 'blue',
            ],
        ]);

        $findings = $this->findings();

        $this->assertCount(2, $findings);
        $this->assertSame([
            SetupValidationFinding::SEVERITY_WARNING,
            SetupValidationFinding::SEVERITY_WARNING,
        ], array_column($findings, 'severity'));

        $this->assertSame([
            'core.contact_status.first_class_field_duplicated_in_meta',
            'core.contact_status.first_class_field_duplicated_in_meta',
        ], array_column($findings, 'code'));
    }

    public function test_it_uses_client_preset_before_default_package(): void
    {
        Config::set('client.preset', 'client_package');
        Config::set('presets.default_package', 'default_package');

        Config::set('presets.packages.client_package', [
            'groups' => [
                'contact_statuses' => [
                    'client_group',
                ],
            ],
        ]);

        Config::set('presets.packages.default_package', [
            'groups' => [
                'contact_statuses' => [
                    'missing_default_group',
                ],
            ],
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.client_group', [
            'new',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions.new', [
            'key' => 'new',
            'name' => 'New',
            'meta' => [],
        ]);

        $this->assertSame([], $this->findings());
    }


    public function test_it_uses_default_package_when_client_preset_is_missing(): void
    {
        Config::set('client.preset', null);
        Config::set('presets.default_package', 'default_package');

        Config::set('presets.packages.default_package', [
            'groups' => [
                'contact_statuses' => [
                    'default_group',
                ],
            ],
        ]);

        Config::set('presets.modules.core.contact-statuses.groups.default_group', [
            'new',
        ]);

        Config::set('presets.modules.core.contact-statuses.definitions.new', [
            'key' => 'new',
            'name' => 'New',
            'meta' => [],
        ]);

        $this->assertSame([], $this->findings());
    }

    /**
     * @param array<int, string> $groups
     */
    private function setPresetPackage(array $groups): void
    {
        Config::set('client.preset', 'test_package');

        Config::set('presets.packages.test_package', [
            'groups' => [
                'contact_statuses' => $groups,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(CoreSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}
