<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Validation\TasksSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TasksSetupValidationContributorTest extends TestCase
{
    public function test_it_accepts_valid_selected_task_presets(): void
    {
        $this->setPresetPackage([
            'general_default',
        ]);

        Config::set('presets.tasks.groups.general_default', [
            'general.follow_up',
            'general.waiting_on_contact',
        ]);

        Config::set('presets.tasks.definitions', [
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
                'related_subject' => [
                    'default' => 'current_contact',
                ],
                'defaults' => [
                    'due' => [
                        'type' => 'delay',
                        'minutes' => 4320,
                    ],
                ],
                'meta' => [],
            ],
        ]);

        $this->assertSame([], $this->findings());
    }

    public function test_it_reports_missing_group_and_missing_definition(): void
    {
        $this->setPresetPackage([
            '__test_missing_task_group__',
            'general_default',
        ]);

        Config::set('presets.tasks.groups.general_default', [
            '__test_missing_task_template__',
        ]);

        $findings = $this->findings();

        $this->assertSame([
            'tasks.group_missing',
            'tasks.definition_missing',
        ], array_column($findings, 'code'));
    }

    public function test_it_reports_invalid_definition_contracts(): void
    {
        $this->setPresetPackage([
            'general_default',
        ]);

        Config::set('presets.tasks.groups.general_default', [
            '__test_missing_title__',
            '__test_bad_responsibility__',
            '__test_bad_assignment__',
            '__test_bad_due__',
            '__test_bad_related_subject__',
            '__test_bad_defaults__',
        ]);

        Config::set('presets.tasks.definitions', [
            '__test_missing_title__' => [
                'name' => 'Missing title',
            ],
            '__test_bad_responsibility__' => [
                'title' => 'Bad responsibility',
                'responsible_party' => 'someone_else',
            ],
            '__test_bad_assignment__' => [
                'title' => 'Bad assignment',
                'assigned_to_strategy' => 'round_robin',
            ],
            '__test_bad_due__' => [
                'title' => 'Bad due',
                'due_offset_minutes' => 'tomorrow',
            ],
            '__test_bad_related_subject__' => [
                'title' => 'Bad related subject',
                'related_subject' => [
                    'default' => 'current_lead',
                ],
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
        $this->assertContains('tasks.due_offset_minutes_invalid', $codes);
        $this->assertContains('tasks.related_subject_default_unsupported', $codes);
        $this->assertContains('tasks.defaults_invalid', $codes);
    }

    public function test_it_reports_key_mismatch_noncanonical_identity_and_ambiguous_cross_group_selection(): void
    {
        $this->setPresetPackage([
            'first_group',
            'second_group',
        ]);

        Config::set('presets.tasks.groups.first_group', [
            'general.call_lead',
            'shared.review',
        ]);

        Config::set('presets.tasks.groups.second_group', [
            'shared.review',
        ]);

        Config::set('presets.tasks.definitions', [
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
        $this->assertContains('tasks.template_key_ambiguous_across_groups', $codes);
    }

    public function test_it_reports_assignment_conflicts_and_incomplete_morphs(): void
    {
        $this->setPresetPackage([
            'general_default',
        ]);

        Config::set('presets.tasks.groups.general_default', [
            '__test_assignment_conflict__',
            '__test_assigned_morph__',
            '__test_responsible_morph__',
        ]);

        Config::set('presets.tasks.definitions', [
            '__test_assignment_conflict__' => [
                'title' => 'Assignment conflict',
                'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
                'assigned_to' => TaskTemplate::ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER,
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
        $this->setPresetPackage([
            'general_default',
        ]);

        Config::set('presets.tasks.groups.general_default', [
            '__test_legacy_task__',
        ]);

        Config::set('presets.tasks.definitions.__test_legacy_task__', [
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

    public function test_it_validates_legacy_group_shape_without_treating_it_as_current_authoring_shape(): void
    {
        $this->setPresetPackage([
            'legacy_group',
        ]);

        Config::set('presets.tasks.groups.legacy_group', [
            'templates' => [
                [
                    'key' => '__test_legacy_template__',
                    'title' => 'Legacy template',
                ],
            ],
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(
            SetupValidationFinding::SEVERITY_WARNING,
            $findings[0]['severity'],
        );
        $this->assertSame(
            'tasks.legacy_group_shape',
            $findings[0]['code'],
        );
    }

    public function test_manager_resolves_tagged_tasks_contributor(): void
    {
        $this->setPresetPackage([
            '__test_missing_task_group_for_registration__',
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'tasks.group_missing',
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
