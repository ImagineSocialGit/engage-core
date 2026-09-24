<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Data\EventGateDecision;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use Carbon\CarbonInterface;

final class EventPromotionGate
{
    public const PROMOTION_READINESS_CAPABILITY = 'promotion';

    public const BLOCKER_LIFECYCLE = 'lifecycle_not_upcoming';

    public function __construct(
        private readonly EventReadinessRegistry $readiness,
        private readonly EventAnnouncementGate $announcement,
    ) {}

    public function decision(
        Event $event,
        ?CarbonInterface $at = null,
    ): EventGateDecision {
        $blockers = [];

        if ($event->status !== EventStatus::Upcoming) {
            $blockers[] = self::BLOCKER_LIFECYCLE;
        }

        foreach ($this->readiness->evaluate(
            $event,
            CoreEventReadinessContributor::CAPABILITY,
        )->codes() as $code) {
            $blockers[] = 'core_readiness.'.$code;
        }

        foreach ($this->readiness->evaluate(
            $event,
            self::PROMOTION_READINESS_CAPABILITY,
        )->codes() as $code) {
            $blockers[] = 'promotion_readiness.'.$code;
        }

        foreach ($this->announcement->decision($event, $at)->blockers as $blocker) {
            $blockers[] = $blocker;
        }

        return new EventGateDecision(array_values(array_unique($blockers)));
    }

    public function allows(
        Event $event,
        ?CarbonInterface $at = null,
    ): bool {
        return $this->decision($event, $at)->allowed();
    }
}