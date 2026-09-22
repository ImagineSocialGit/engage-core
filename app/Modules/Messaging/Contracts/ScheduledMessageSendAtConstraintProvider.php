<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Data\ScheduledMessagePlanningContext;
use Illuminate\Support\Carbon;

interface ScheduledMessageSendAtConstraintProvider
{
    public const TAG = 'messaging.scheduled_message_send_at_constraints';

    public function constrain(
        ScheduledMessagePlanningContext $context,
        Carbon $sendAt,
    ): Carbon;
}