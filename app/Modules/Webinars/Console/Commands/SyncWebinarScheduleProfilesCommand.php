<?php

namespace App\Modules\Webinars\Console\Commands;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Console\Command;

class SyncWebinarScheduleProfilesCommand extends Command
{
    protected $signature = 'webinars:schedule-profiles:sync {--force : Reserved for future customized profile handling}';

    protected $description = 'Sync config-defined Webinars schedule profiles into DB-owned selectable profiles.';

    public function handle(SyncWebinarScheduleProfilesAction $syncWebinarScheduleProfiles): int
    {
        $result = $syncWebinarScheduleProfiles->handle(
            force: (bool) $this->option('force'),
        );

        $this->components->info(sprintf(
            'Webinar schedule profiles synced. Profiles created: %d. Profiles updated: %d. Items created: %d. Items updated: %d. Items disabled: %d.',
            $result['profiles_created'],
            $result['profiles_updated'],
            $result['items_created'],
            $result['items_updated'],
            $result['items_disabled'],
        ));

        return self::SUCCESS;
    }
}
