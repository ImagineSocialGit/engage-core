<?php

namespace App\Modules\Webinars\Console\Commands;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Console\Command;

class SyncWebinarScheduleProfilesCommand extends Command
{
    protected $signature = 'webinars:schedule-profiles:sync {--force : Overwrite customized webinar schedule profiles and profile items}';

    protected $description = 'Sync config-defined Webinar schedule profiles and their immutable Messaging chains.';

    public function handle(SyncWebinarScheduleProfilesAction $syncWebinarScheduleProfiles): int
    {
        $result = $syncWebinarScheduleProfiles->handle(
            force: (bool) $this->option('force'),
            requireMessageChains: true,
        );

        $this->components->info(sprintf(
            'Webinar schedule profiles synced. Profiles created: %d. Profiles updated: %d. Profiles customized skipped: %d. Items created: %d. Items updated: %d. Items customized skipped: %d. Items disabled: %d. Chains created: %d. Chains updated: %d. Chains customized skipped: %d. Chain versions published: %d. Chain versions reused: %d. Bindings created: %d. Bindings updated: %d. Bindings disabled: %d. Chain publication deferred: %d.',
            $result['profiles_created'],
            $result['profiles_updated'],
            $result['profiles_skipped'],
            $result['items_created'],
            $result['items_updated'],
            $result['items_skipped'],
            $result['items_disabled'],
            $result['chains_created'],
            $result['chains_updated'],
            $result['chains_skipped'],
            $result['chain_versions_published'],
            $result['chain_versions_reused'],
            $result['chain_bindings_created'],
            $result['chain_bindings_updated'],
            $result['chain_bindings_disabled'],
            $result['chains_deferred'],
        ));

        return self::SUCCESS;
    }
}