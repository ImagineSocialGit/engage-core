<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;

final readonly class AutomationMessageScheduled
{
    /** @param array<int, ScheduledMessage> $scheduledMessages */
    public function __construct(
        public AutomationActionContext $context,
        public SendMessageAutomationDefinition $definition,
        public array $scheduledMessages,
    ) {}
}