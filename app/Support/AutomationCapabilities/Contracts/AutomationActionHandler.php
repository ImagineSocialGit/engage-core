<?php

namespace App\Support\AutomationCapabilities\Contracts;

use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;

interface AutomationActionHandler
{
    public function key(): string;

    public function handle(AutomationActionContext $context): AutomationActionResult;
}
