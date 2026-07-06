<?php

namespace App\Modules\Messaging\Console\Commands;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use Illuminate\Console\Command;

class SyncMessageTemplatePresetsCommand extends Command
{
    protected $signature = 'messaging:template-presets:sync {--force : Overwrite customized presets and reactivate synced assignments}';

    protected $description = 'Sync config-defined Messaging templates into DB-owned template presets and default assignments.';

    public function handle(SyncMessageTemplatePresetsAction $syncMessageTemplatePresets): int
    {
        $result = $syncMessageTemplatePresets->handle(
            force: (bool) $this->option('force'),
        );

        $this->components->info(sprintf(
            'Message template presets synced. Created: %d. Updated: %d. Customized skipped: %d. Assignments created: %d. Assignments preserved: %d.',
            $result['created'],
            $result['updated'],
            $result['customized_skipped'],
            $result['assignments_created'],
            $result['assignments_preserved'],
        ));

        return self::SUCCESS;
    }
}
