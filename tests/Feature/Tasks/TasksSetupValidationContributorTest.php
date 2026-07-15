<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Validation\TasksSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TasksSetupValidationContributorTest extends TestCase
{
    public function test_it_accepts_valid_selected_task_presets_with_link_defaults(): void
    {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.tasks.tasks.groups.default', [
            'general.follow_up',
            'general.waiting_on_contact',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions', [
            'general.follow_up' => [
                'name' => 'Follow up',
                'title' => 'Follow up',
                'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
                'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                'due_offset_minutes' => 1440,
                'defaults' => [],
                'meta' => [],
            ],
            'general.waiting_on_contact' => [
                'name' => 'Waiting on contact',
                'title' => 'Contact needs to provide something',
                'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
                'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                'due_offset_minutes' => 4320,
                'link_defaults' => [
                    [
                        'role' => TaskLink::ROLE_SUBJECT,
                        'source' => TaskTemplate::LINK_SOURCE_CURRENT_CONTACT,
                    ],
                ],
                'defaults' => [],
                'meta' => [],
            ],
        ]);

        $this->assertSame([], $this->findings());
    }

    public function test_it_reports_invalid_definition_contracts(): void
    {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.tasks.tasks.groups.default', [
            '__test_missing_title__',
            '__test_bad_responsibility__',
            '__test_bad_assignment__',
            '__test_unavailable_assignment__',
            '__test_bad_due__',
            '__test_bad_defaults__',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions', [
            '__test_missing_title__' => [
                'name' => 'Missing title',
            ],
            '__test_bad_responsibility__' => [
                'title' => 'Bad responsibility',
                'responsible_party' => 'someone_else',
            ],
            '__test_bad_assignment__' => [
                'title' => 'Bad assignment',
                'assigned_to_strategy' => [],
            ],
            '__test_unavailable_assignment__' => [
                'title' => 'Unavailable assignment',
                'assigned_to_strategy' => 'round_robin',
            ],
            '__test_bad_due__' => [
                'title' => 'Bad due',
                'due_offset_minutes' => 'tomorrow',
            ],
            '__test_bad_defaults__' => [
                'title' => 'Bad defaults',
                'defaults' => 'not-an-array',
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('tasks.definition_title_missing', $codes);
        $this->assertContains('tasks.responsible_party_invalid', $codes);
        $this->assertContains('tasks.assignment_strategy_invalid', $codes);
        $this->assertContains('tasks.assignment_strategy_unavailable', $codes);
        $this->assertContains('tasks.due_offset_minutes_invalid', $codes);
        $this->assertContains('tasks.defaults_invalid', $codes);
    }

    public function test_it_rejects_obsolete_or_invalid_relationship_default_shapes(): void
    {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.tasks.tasks.groups.default', [
            '__test_obsolete_related_subject__',
            '__test_bad_role__',
            '__test_bad_source__',
            '__test_duplicate_link_default__',
            '__test_nested_relationship_defaults__',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions', [
            '__test_obsolete_related_subject__' => [
                'title' => 'Obsolete relationship contract',
                'related_subject' => [
                    'default' => 'current_contact',
                ],
            ],
            '__test_bad_role__' => [
                'title' => 'Bad link role',
                'link_defaults' => [
                    [
                        'role' => 'owner',
                        'source' => TaskTemplate::LINK_SOURCE_CURRENT_CONTACT,
                    ],
                ],
            ],
            '__test_bad_source__' => [
                'title' => 'Bad link source',
                'link_defaults' => [
                    [
                        'role' => TaskLink::ROLE_SUBJECT,
                        'source' => 'current_pet',
                    ],
                ],
            ],
            '__test_duplicate_link_default__' => [
                'title' => 'Duplicate link default',
                'link_defaults' => [
                    [
                        'role' => TaskLink::ROLE_SUBJECT,
                        'source' => TaskTemplate::LINK_SOURCE_CURRENT_CONTACT,
                    ],
                    [
                        'role' => TaskLink::ROLE_SUBJECT,
                        'source' => TaskTemplate::LINK_SOURCE_CURRENT_CONTACT,
                    ],
                ],
            ],
            '__test_nested_relationship_defaults__' => [
                'title' => 'Nested relationship defaults',
                'defaults' => [
                    'links' => [],
                ],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('tasks.related_subject_obsolete', $codes);
        $this->assertContains('tasks.link_default_role_invalid', $codes);
        $this->assertContains('tasks.link_default_source_invalid', $codes);
        $this->assertContains('tasks.link_default_duplicate', $codes);
        $this->assertContains('tasks.relationship_defaults_not_first_class', $codes);
    }

    public function test_it_reports_key_mismatch_and_noncanonical_identity_while_allowing_cross_group_reuse(): void
    {
        $this->setPresetPackage([
            'first_group',
            'second_group',
        ]);

        Config::set('presets.modules.tasks.tasks.groups.first_group', [
            'general.call_lead',
            'shared.review',
        ]);

        Config::set('presets.modules.tasks.tasks.groups.second_group', [
            'shared.review',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions', [
            'general.call_lead' => [
                'key' => 'general.call_contact',
                'title' => 'Call contact',
            ],
            'shared.review' => [
                'title' => 'Review',
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('tasks.definition_key_mismatch', $codes);
        $this->assertContains('tasks.noncanonical_identifier', $codes);
        $this->assertNotContains('tasks.template_key_ambiguous_across_groups', $codes);
    }

    public function test_it_reports_assignment_conflicts_and_incomplete_morphs(): void
    {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.tasks.tasks.groups.default', [
            '__test_assignment_conflict__',
            '__test_assigned_morph__',
            '__test_responsible_morph__',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions', [
            '__test_assignment_conflict__' => [
                'title' => 'Assignment conflict',
                'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                'assigned_to' => 'another_strategy',
            ],
            '__test_assigned_morph__' => [
                'title' => 'Assigned morph',
                'assigned_to_type' => 'team_member',
            ],
            '__test_responsible_morph__' => [
                'title' => 'Responsible morph',
                'responsible_id' => 10,
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('tasks.assignment_strategy_conflict', $codes);
        $this->assertContains('tasks.assigned_to_morph_incomplete', $codes);
        $this->assertContains('tasks.responsible_morph_incomplete', $codes);
    }

    public function test_it_warns_for_legacy_due_offset_and_first_class_defaults_duplication(): void
    {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.tasks.tasks.groups.default', [
            '__test_legacy_task__',
        ]);

        Config::set('presets.modules.tasks.tasks.definitions.__test_legacy_task__', [
            'title' => 'Legacy task',
            'due_offset_days' => 2,
            'defaults' => [
                'priority' => 'high',
            ],
        ]);

        $findings = $this->findings();

        $this->assertSame([
            SetupValidationFinding::SEVERITY_WARNING,
            SetupValidationFinding::SEVERITY_WARNING,
        ], array_column($findings, 'severity'));

        $this->assertSame([
            'tasks.legacy_due_offset_days',
            'tasks.first_class_field_duplicated_in_defaults',
        ], array_column($findings, 'code'));
    }

    public function test_manager_resolves_tagged_tasks_contributor(): void
    {
        $this->setPresetPackage([
            '__test_missing_task_group_for_registration__',
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'app.presets.selected_group_missing',
            array_map(
                fn (SetupValidationFinding $finding): string => $finding->code,
                $result->findings(),
            ),
        );
    }

    /**
     * @param array<int, string> $groups
     */
    private function setPresetPackage(array $groups): void
    {
        Config::set('client.preset', 'tasks_validation_test');

        Config::set('presets.packages.tasks_validation_test', [
            'groups' => [
                'contact_statuses' => [],
                'tasks' => $groups,
                'flow_routes' => [],
                'campaigns' => [],
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
                app(TasksSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}
