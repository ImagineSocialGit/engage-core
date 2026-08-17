<?php

namespace App\Modules\Reporting\Actions;

use Illuminate\Support\Facades\Storage;
use Throwable;

final class PruneReportingImportFilesAction
{
    private const DIRECTORY = 'reporting-imports';
    private const MAX_AGE_SECONDS = 21600;

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;
        $cutoff = time() - self::MAX_AGE_SECONDS;

        foreach ($disk->files(self::DIRECTORY) as $path) {
            try {
                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }

                if ($disk->delete($path)) {
                    $deleted++;
                }
            } catch (Throwable) {
                // A transient filesystem race should not block the scheduler.
            }
        }

        return $deleted;
    }
}