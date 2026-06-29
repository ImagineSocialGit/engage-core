<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskPresetDefinition;
use App\Modules\Tasks\Data\TaskPresetSyncResult;
use App\Modules\Tasks\Models\TaskTemplate;

class SyncTaskPresetsAction
{
    public function handle(string $presetKey): TaskPresetSyncResult
    {
        $result = new TaskPresetSyncResult();

        $groupKeys = config("presets.presets.{$presetKey}.tasks.groups", []);

        if (! is_array($groupKeys) || $groupKeys === []) {
            return $result;
        }

        foreach ($groupKeys as $groupKey) {
            if (! is_string($groupKey) || trim($groupKey) === '') {
                $result->skipped();
                $result->warn('Skipped task preset group with invalid group key.');

                continue;
            }

            $this->syncGroup(trim($groupKey), $result);
        }

        return $result;
    }

    private function syncGroup(string $groupKey, TaskPresetSyncResult $result): void
    {
        $group = config("presets.tasks.groups.{$groupKey}");

        if (! is_array($group)) {
            $result->skipped();
            $result->error("Task preset group [{$groupKey}] does not exist.");

            return;
        }

        $templates = $group['templates'] ?? [];

        if (! is_array($templates)) {
            $result->skipped();
            $result->error("Task preset group [{$groupKey}] has invalid templates.");

            return;
        }

        foreach ($templates as $template) {
            if (! is_array($template)) {
                $result->skipped();
                $result->warn("Skipped invalid task template in group [{$groupKey}].");

                continue;
            }

            $this->syncTemplate(
                definition: TaskPresetDefinition::fromArray($groupKey, $template),
                result: $result,
            );
        }
    }

    private function syncTemplate(
        TaskPresetDefinition $definition,
        TaskPresetSyncResult $result,
    ): void {
        if (! $definition->isValid()) {
            $result->skipped();
            $result->error(sprintf(
                'Skipped task template [%s] in group [%s]: %s.',
                $definition->key ?? 'missing-key',
                $definition->groupKey,
                $definition->invalidReason ?? 'invalid_definition',
            ));

            return;
        }

        $template = TaskTemplate::query()
            ->where('key', $definition->key)
            ->first();

        if (! $template) {
            TaskTemplate::query()->create([
                'key' => $definition->key,
                ...$definition->attributes(),
            ]);

            $result->created();

            return;
        }

        $template->fill($definition->attributes());

        if (! $template->isDirty()) {
            $result->skipped();

            return;
        }

        $template->save();

        $result->updated();
    }
}