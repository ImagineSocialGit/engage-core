<?php

namespace App\Modules\FlowRoutes\Enums;

enum FlowRoutePointType: string
{
    case Noop = 'noop';
    case Wait = 'wait';
    case EventWait = 'event_wait';
    case Condition = 'condition';
    case BranchEvaluate = 'branch_evaluate';
    case ChangeStatus = 'change_status';
    case CreateTask = 'create_task';
    case SendMessage = 'send_message';
    case EnrollCampaign = 'enroll_campaign';
    case CancelCampaign = 'cancel_campaign';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
