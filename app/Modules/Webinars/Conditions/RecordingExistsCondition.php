<?php

namespace App\Modules\Webinars\Conditions;

use App\Modules\Webinars\Contracts\WebinarPostEventSendCondition;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRecordingAvailabilityResolver;

final class RecordingExistsCondition implements WebinarPostEventSendCondition
{
    public function __construct(private readonly WebinarRecordingAvailabilityResolver $recordings) {}

    public function key(): string
    {
        return 'recording_exists';
    }

    public function label(): string
    {
        return 'Recording exists';
    }

    public function matches(WebinarRegistration $registration, string $activation, string $dueAt): bool
    {
        return $this->recordings->exists($registration->webinar, $activation, $dueAt);
    }
}