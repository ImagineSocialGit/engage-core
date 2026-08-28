<?php

namespace App\Modules\Scheduling\Console\Commands;

use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use Illuminate\Console\Command;

final class SyncAppointmentCommunicationsCatalogCommand extends Command
{
    protected $signature = 'scheduling:communications:sync-catalog';

    protected $description = 'Synchronize the configured appointment communication copy into the Messaging template catalog.';

    public function handle(AppointmentCommunications $communications): int
    {
        if (! $communications->available()) {
            $this->warn('Appointment communications are unavailable because Messaging is not enabled with Scheduling.');

            return self::SUCCESS;
        }

        $plan = $communications->plan();
        $steps = is_array($plan['steps'] ?? null) ? $plan['steps'] : [];

        if (! (bool) ($plan['configured'] ?? false) || $steps === []) {
            $this->info('No configured appointment communication schedule needs catalog synchronization.');

            return self::SUCCESS;
        }

        $communications->saveSchedule($steps);

        $this->info('Appointment communication templates are synchronized with the Message Templates library.');

        return self::SUCCESS;
    }
}