<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPresetSyncOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_definition_selected_by_multiple_groups_is_persisted_once(): void
    {
        $resolved = $this->resolved(
            selectedGroups: ['default', 'extended_default'],
            selectedContributors: ['tasks'],
            definitions: [
                'general.follow_up' => [
                    'title' => 'Follow up',
                ],
            ],
            provenance: [
                'general.follow_up' => [
                    'contributor' => 'tasks',
                    'source' => 'presets.modules.tasks.tasks',
                ],
            ],
            definitionGroups: [
                'general.follow_up' => ['default', 'extended_default'],
            ],
        );

        app(SyncTaskPresetsAction::class)->handle($resolved);

        $this->assertSame(
            1,
            TaskTemplate::query()->where('key', 'general.follow_up')->count(),
        );

        $template = TaskTemplate::query()
            ->where('key', 'general.follow_up')
            ->firstOrFail();

        $this->assertSame('tasks', data_get($template->meta, 'preset.contributor'));
        $this->assertArrayNotHasKey('group_key', $template->meta['preset']);
    }

    public function test_stale_cleanup_is_scoped_to_selected_contributor_ownership(): void
    {
        $this->presetTemplate('general.old', 'tasks');
        $this->presetTemplate('webinar.review_reply', 'webinars');

        $resolved = $this->resolved(
            selectedGroups: ['default'],
            selectedContributors: ['tasks'],
            definitions: [
                'general.follow_up' => [
                    'title' => 'Follow up',
                ],
            ],
            provenance: [
                'general.follow_up' => [
                    'contributor' => 'tasks',
                    'source' => 'presets.modules.tasks.tasks',
                ],
            ],
            definitionGroups: [
                'general.follow_up' => ['default'],
            ],
        );

        app(SyncTaskPresetsAction::class)->handle($resolved);

        $this->assertDatabaseMissing('task_templates', [
            'key' => 'general.old',
        ]);

        $this->assertDatabaseHas('task_templates', [
            'key' => 'webinar.review_reply',
        ]);
    }

    public function test_customized_stale_template_is_preserved_without_force(): void
    {
        $template = $this->presetTemplate('general.old', 'tasks', customized: true);

        $resolved = $this->resolved(
            selectedGroups: ['default'],
            selectedContributors: ['tasks'],
            definitions: [],
            provenance: [],
            definitionGroups: [],
        );

        $result = app(SyncTaskPresetsAction::class)->handle($resolved);

        $this->assertDatabaseHas('task_templates', [
            'id' => $template->id,
            'key' => 'general.old',
        ]);

        $this->assertSame(1, $result->customizedSkipped);
    }

    public function test_force_removes_customized_stale_template(): void
    {
        $template = $this->presetTemplate('general.old', 'tasks', customized: true);

        $resolved = $this->resolved(
            selectedGroups: ['default'],
            selectedContributors: ['tasks'],
            definitions: [],
            provenance: [],
            definitionGroups: [],
        );

        app(SyncTaskPresetsAction::class)->handle($resolved, force: true);

        $this->assertDatabaseMissing('task_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_selected_contributor_can_retire_its_final_definition(): void
    {
        $this->presetTemplate('webinar.review_reply', 'webinars');

        $resolved = $this->resolved(
            selectedGroups: ['webinar_default'],
            selectedContributors: ['webinars'],
            definitions: [],
            provenance: [],
            definitionGroups: [],
        );

        app(SyncTaskPresetsAction::class)->handle($resolved);

        $this->assertDatabaseMissing('task_templates', [
            'key' => 'webinar.review_reply',
        ]);
    }

    private function presetTemplate(
        string $key,
        string $contributor,
        bool $customized = false,
    ): TaskTemplate {
        return TaskTemplate::factory()->create([
            'key' => $key,
            'source' => TaskTemplate::SOURCE_PRESET,
            'is_customized' => $customized,
            'customized_at' => $customized ? now() : null,
            'meta' => [
                'preset' => [
                    'contributor' => $contributor,
                    'task_template_key' => $key,
                    'source_version' => 'test',
                ],
            ],
        ]);
    }

    /**
     * @param array<int, string> $selectedGroups
     * @param array<int, string> $selectedContributors
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array{contributor: string, source: string}> $provenance
     * @param array<string, array<int, string>> $definitionGroups
     */
    private function resolved(
        array $selectedGroups,
        array $selectedContributors,
        array $definitions,
        array $provenance,
        array $definitionGroups,
    ): ResolvedPresetDomain {
        return new ResolvedPresetDomain(
            presetKey: 'test',
            domain: PresetDomain::Tasks,
            selectedGroups: $selectedGroups,
            selectedContributors: $selectedContributors,
            definitionKeys: array_keys($definitions),
            definitions: $definitions,
            provenance: $provenance,
            definitionGroups: $definitionGroups,
        );
    }
}
