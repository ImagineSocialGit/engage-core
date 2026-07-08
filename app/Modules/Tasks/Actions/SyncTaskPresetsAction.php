<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskPresetDefinition;
use App\Modules\Tasks\Data\TaskPresetSyncResult;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Support\Facades\DB;

class SyncTaskPresetsAction
{
    public function handle(string $presetKey, bool $force = false): TaskPresetSyncResult
    {
        $result = new TaskPresetSyncResult();

        $groupKeys = $this->normalizeStringList(
            config("presets.packages.{$presetKey}.groups.tasks", []),
        );

        if ($groupKeys === []) {
            return $result;
        }

        DB::transaction(function () use ($groupKeys, $result, $force): void {
            foreach ($groupKeys as $groupKey) {
                $definitions = $this->definitionsForGroup($groupKey, $result);

                if ($definitions === []) {
                    continue;
                }

                $seenKeys = [];

                foreach ($definitions as $definitionData) {
                    $definition = TaskPresetDefinition::fromArray($groupKey, $definitionData);

                    if ($definition->key !== null) {
                        $seenKeys[] = $definition->key;
                    }

                    $this->syncTemplate(
                        definition: $definition,
                        result: $result,
                        force: $force,
                    );
                }

                $this->removeStaleTemplates(
                    groupKey: $groupKey,
                    activeKeys: array_values(array_unique($seenKeys)),
                    result: $result,
                    force: $force,
                );
            }
        });

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionsForGroup(string $groupKey, TaskPresetSyncResult $result): array
    {
        $group = config("presets.tasks.groups.{$groupKey}");

        if (! is_array($group)) {
            $result->skipped();
            $result->error("Task preset group [{$groupKey}] does not exist.");

            return [];
        }

        if (array_is_list($group)) {
            return $this->definitionsFromKeys($groupKey, $group, $result);
        }

        $legacyTemplates = $group['templates'] ?? null;

        if (is_array($legacyTemplates)) {
            return array_values(array_filter(
                $legacyTemplates,
                fn (mixed $template): bool => is_array($template),
            ));
        }

        $result->skipped();
        $result->error("Task preset group [{$groupKey}] has invalid template references.");

        return [];
    }

    /**
     * @param array<int, mixed> $templateKeys
     * @return array<int, array<string, mixed>>
     */
    private function definitionsFromKeys(
        string $groupKey,
        array $templateKeys,
        TaskPresetSyncResult $result,
    ): array {
        $definitions = [];

        foreach ($this->normalizeStringList($templateKeys) as $templateKey) {
            $definitionsConfig = config('presets.tasks.definitions', []);
            $definition = is_array($definitionsConfig) ? ($definitionsConfig[$templateKey] ?? null) : null;

            if (! is_array($definition)) {
                $result->skipped();
                $result->error("Task preset definition [{$templateKey}] referenced by group [{$groupKey}] does not exist.");

                continue;
            }

            $definitions[] = array_replace(['key' => $templateKey], $definition);
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }

    private function syncTemplate(
        TaskPresetDefinition $definition,
        TaskPresetSyncResult $result,
        bool $force,
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

        $template = TaskTemplate::query()->firstOrNew([
            'key' => $definition->key,
        ]);

        $wasRecentlyCreated = ! $template->exists;

        if ($template->exists && $template->is_customized && ! $force) {
            $result->customizedSkipped();
            $result->warn("Task template [{$definition->key}] was preserved because it is customized.");

            return;
        }

        $template->forceFill(array_replace($definition->attributes(), [
            'key' => $definition->key,
            'is_customized' => $force ? false : (bool) $template->is_customized,
            'customized_at' => $force ? null : $template->customized_at,
            'meta' => array_replace_recursive($template->meta ?? [], [
                'preset' => [
                    'group_key' => $definition->groupKey,
                    'task_template_key' => $definition->key,
                    'source_version' => $definition->sourceVersion,
                ],
                'definition' => $definition->meta,
            ]),
        ]));

        if (! $template->isDirty()) {
            $result->skipped();

            return;
        }

        $template->save();

        $result->{$wasRecentlyCreated ? 'created' : 'updated'}();
    }

    /**
     * @param array<int, string> $activeKeys
     */
    private function removeStaleTemplates(
        string $groupKey,
        array $activeKeys,
        TaskPresetSyncResult $result,
        bool $force,
    ): void {
        if ($activeKeys === []) {
            return;
        }

        TaskTemplate::query()
            ->group($groupKey)
            ->whereNotIn('key', $activeKeys)
            ->each(function (TaskTemplate $template) use ($result, $force): void {
                if ($template->is_customized && ! $force) {
                    $result->customizedSkipped();
                    $result->warn("Stale task template [{$template->key}] was preserved because it is customized.");

                    return;
                }

                $template->delete();
                $result->removed();
            });
    }
}
