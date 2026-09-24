<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Data\EventGateDecision;
use App\Modules\Events\Models\Event;
use Carbon\CarbonInterface;

final class EventAnnouncementGate
{
    public const BLOCKER_MISSING = 'announcement_at_missing';

    public const BLOCKER_EMBARGO_ACTIVE = 'announcement_embargo_active';

    public function decision(
        Event $event,
        ?CarbonInterface $at = null,
    ): EventGateDecision {
        if ($event->announcement_at === null) {
            return new EventGateDecision([
                self::BLOCKER_MISSING,
            ]);
        }

        $at ??= now();

        if ($event->announcement_at->isAfter($at)) {
            return new EventGateDecision([
                self::BLOCKER_EMBARGO_ACTIVE,
            ]);
        }

        return new EventGateDecision();
    }

    public function allows(
        Event $event,
        ?CarbonInterface $at = null,
    ): bool {
        return $this->decision($event, $at)->allowed();
    }
}