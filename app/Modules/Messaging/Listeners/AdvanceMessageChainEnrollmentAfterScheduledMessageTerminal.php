<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;

class AdvanceMessageChainEnrollmentAfterScheduledMessageTerminal
{
    public function __construct(
        private readonly ProcessMessageChainEnrollmentAction $processEnrollment,
    ) {}

    public function handle(
        ScheduledMessageSent|ScheduledMessageSkipped|ScheduledMessageFailed $event,
    ): void {
        $this->processEnrollment->handleTerminal(
            $event->scheduledMessage,
        );
    }
}