<?php

namespace App\Modules\Webinars\Conditions;

use App\Modules\Webinars\Contracts\WebinarPostEventSendCondition;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRecordingAvailabilityResolver;

final class RecordingMissingCondition implements WebinarPostEventSendCondition
{
    public function __construct(private readonly WebinarRecordingAvailabilityResolver $recordings) {}

    public function key(): string
    {
        return 'recording_missing';
    }

    public function label(): string
    {
        return 'Recording does not exist';
    }

    public function matches(WebinarRegistration $registration, string $activation, string $dueAt): bool
    {
        return ! $this->recordings->exists($registration->webinar, $activation, $dueAt);
    }
}