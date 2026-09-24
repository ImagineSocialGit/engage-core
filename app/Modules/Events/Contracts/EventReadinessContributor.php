<?php

namespace App\Modules\Events\Contracts;

use App\Modules\Events\Data\EventReadinessFinding;
use App\Modules\Events\Models\Event;

interface EventReadinessContributor
{
    public function capability(): string;

    /**
     * @return iterable<int, EventReadinessFinding>
     */
    public function findings(Event $event): iterable;
}