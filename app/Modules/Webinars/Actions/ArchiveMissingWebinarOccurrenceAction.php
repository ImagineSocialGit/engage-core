<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus;
use App\Modules\Webinars\Models\Webinar;
use LogicException;

class ArchiveMissingWebinarOccurrenceAction
{
    public function handle(Webinar $webinar): Webinar
    {
        if (! $webinar->isProviderMissing()) {
            throw new LogicException(
                'Only an occurrence that is missing from Zoom can be kept as history.',
            );
        }

        $webinar->forceFill([
            'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Archived->value,
            'provider_archived_at' => now(),
        ])->save();

        return $webinar->refresh();
    }
}