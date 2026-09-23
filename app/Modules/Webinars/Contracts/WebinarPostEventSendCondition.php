<?php

namespace App\Modules\Webinars\Contracts;

use App\Modules\Webinars\Models\WebinarRegistration;

interface WebinarPostEventSendCondition
{
    public const TAG = 'webinars.post_event_send_conditions';

    public function key(): string;

    public function label(): string;

    public function matches(WebinarRegistration $registration, string $activation, string $dueAt): bool;
}