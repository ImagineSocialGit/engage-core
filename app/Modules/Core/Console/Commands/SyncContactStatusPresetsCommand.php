<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use Illuminate\Console\Command;
use Throwable;

class SyncContactStatusPresetsCommand extends Command
{
    protected $signature = 'contact-statuses:sync-presets
        {preset? : Optional preset key, such as webinar_funnel or general_contact_engagement}
        {--force : Overwrite customized ContactStatus records with preset definitions}';

    protected $description = 'Sync contact status preset definitions into database-owned ContactStatus records.';

    public function handle(SyncContactStatusPresetsAction $syncContactStatusPresets): int
    {
        $presetKey = $this->argument('preset');

        $presetKey = is_string($presetKey) && trim($presetKey) !== ''
            ? trim($presetKey)
            : null;

        try {
            $result = $syncContactStatusPresets->handle(
                presetKey: $presetKey,
                force: (bool) $this->option('force'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Contact status presets synced.');

        $this->table(
            ['Item', 'Count'],
            [
                ['Created', $result['created']],
                ['Updated', $result['updated']],
                ['Skipped', $result['skipped']],
            ],
        );

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }
}
