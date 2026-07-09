<?php

namespace App\Modules\Webinars\Console\Commands;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Console\Command;

class SyncWebinarScheduleProfilesCommand extends Command
{
    protected $signature = 'webinars:schedule-profiles:sync {--force : Overwrite customized webinar schedule profiles and profile items}';

    protected $description = 'Sync config-defined Webinars schedule profiles into DB-owned selectable profiles.';

    public function handle(SyncWebinarScheduleProfilesAction $syncWebinarScheduleProfiles): int
    {
        $result = $syncWebinarScheduleProfiles->handle(
            force: (bool) $this->option('force'),
        );

        $this->components->info(sprintf(
            'Webinar schedule profiles synced. Profiles created: %d. Profiles updated: %d. Profiles customized skipped: %d. Items created: %d. Items updated: %d. Items customized skipped: %d. Items disabled: %d.',
            $result['profiles_created'],
            $result['profiles_updated'],
            $result['profiles_skipped'],
            $result['items_created'],
            $result['items_updated'],
            $result['items_skipped'],
            $result['items_disabled'],
        ));

        return self::SUCCESS;
    }
}
