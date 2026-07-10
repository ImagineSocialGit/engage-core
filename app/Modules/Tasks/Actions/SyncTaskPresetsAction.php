<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskPresetDefinition;
use App\Modules\Tasks\Data\TaskPresetSyncResult;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncTaskPresetsAction
{
    public function handle(
        ResolvedPresetDomain $resolved,
        bool $force = false,
    ): TaskPresetSyncResult {
        if ($resolved->domain !== PresetDomain::Tasks) {
            throw new InvalidArgumentException(sprintf(
                'Task preset sync requires domain [%s]; received [%s].',
                PresetDomain::Tasks->value,
                $resolved->domain->value,
            ));
        }

        $result = new TaskPresetSyncResult();

        DB::transaction(function () use ($resolved, $result, $force): void {
            $activeKeysByContributor = array_fill_keys(
                $resolved->selectedContributors,
                [],
            );

            foreach ($resolved->definitions as $templateKey => $definitionData) {
                $contributor = $resolved->provenance[$templateKey]['contributor'] ?? null;

                if (! is_string($contributor) || trim($contributor) === '') {
                    throw new InvalidArgumentException(
                        "Task preset definition [{$templateKey}] is missing contributor provenance."
                    );
                }

                $contributor = trim($contributor);

                $activeKeysByContributor[$contributor] ??= [];
                $activeKeysByContributor[$contributor][] = $templateKey;

                $definition = TaskPresetDefinition::fromArray(
                    array_replace(['key' => $templateKey], $definitionData),
                );

                $this->syncTemplate(
                    definition: $definition,
                    contributor: $contributor,
                    result: $result,
                    force: $force,
                );
            }

            foreach ($activeKeysByContributor as $contributor => $activeKeys) {
                $this->removeStaleTemplates(
                    contributor: $contributor,
                    activeKeys: array_values(array_unique($activeKeys)),
                    result: $result,
                    force: $force,
                );
            }
        });

        return $result;
    }

    private function syncTemplate(
        TaskPresetDefinition $definition,
        string $contributor,
        TaskPresetSyncResult $result,
        bool $force,
    ): void {
        if (! $definition->isValid()) {
            $result->skipped();
            $result->error(sprintf(
                'Skipped task template [%s]: %s.',
                $definition->key ?? 'missing-key',
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
                    'contributor' => $contributor,
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
        string $contributor,
        array $activeKeys,
        TaskPresetSyncResult $result,
        bool $force,
    ): void {
        $query = TaskTemplate::query()
            ->where('source', TaskTemplate::SOURCE_PRESET)
            ->where('meta->preset->contributor', $contributor);

        if ($activeKeys !== []) {
            $query->whereNotIn('key', $activeKeys);
        }

        $query->each(function (TaskTemplate $template) use ($result, $force): void {
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
