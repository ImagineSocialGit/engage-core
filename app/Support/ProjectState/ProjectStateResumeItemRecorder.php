<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProjectStateResumeItemRecorder
{
    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $definition
     */
    public function record(
        string $table,
        mixed $targetId,
        array $sourceRow,
        array $definition,
    ): void {
        if ($definition['resume_items'] === []) {
            return;
        }

        if (! Schema::hasTable('project_state_resume_items')) {
            throw new RuntimeException(
                'Project-state resume tracking is unavailable until its migration has been applied.'
            );
        }

        foreach ($definition['resume_items'] as $resumeItem) {
            $sourceStatus = $this->sourceStatus(
                sourceRow: $sourceRow,
                resumeItem: $resumeItem,
            );

            if ($sourceStatus === null
                || ! in_array($sourceStatus, $resumeItem['statuses'], true)
            ) {
                continue;
            }

            $now = now();

            $this->connection()
                ->table('project_state_resume_items')
                ->updateOrInsert(
                    [
                        'source_table' => $table,
                        'source_record_id' => (string) $targetId,
                    ],
                    [
                        'category' => $resumeItem['category'],
                        'original_status' => $sourceStatus,
                        'state' => ProjectStateResumeManager::STATE_PENDING,
                        'result_code' => null,
                        'resumed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
        }
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $resumeItem
     */
    private function sourceStatus(
        array $sourceRow,
        array $resumeItem,
    ): ?string {
        $value = $resumeItem['column'] !== null
            ? ($sourceRow[$resumeItem['column']] ?? null)
            : (is_array($sourceRow[$resumeItem['json_column']] ?? null)
                ? Arr::get(
                    $sourceRow[$resumeItem['json_column']],
                    $resumeItem['path'],
                )
                : null);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}