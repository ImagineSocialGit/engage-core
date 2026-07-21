<?php

namespace App\Integrations\Webinars\Zoom;

use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderEventType;

class ZoomWebinarService
{
    private readonly ZoomEventService $events;

    public function __construct(
        ZoomOAuthService $auth,
        ZoomAttendanceMapper $attendanceMapper,
    ) {
        $this->events = new ZoomEventService(
            auth: $auth,
            attendanceMapper: $attendanceMapper,
        );
    }

    public function registerAttendee(string $webinarId, array $data): array
    {
        return $this->events->registerAttendee(
            WebinarProviderEventType::Webinar,
            $webinarId,
            $data,
        );
    }

    public function cancelRegistrant(
        string $webinarId,
        string $registrantId,
        ?string $occurrenceId = null,
    ): void {
        $this->events->cancelRegistrant(
            WebinarProviderEventType::Webinar,
            $webinarId,
            $registrantId,
            $occurrenceId,
        );
    }

    public function listPastWebinarParticipants(
        string $webinarId,
    ): ProviderAttendanceSnapshot {
        return $this->events->listPastParticipants(
            WebinarProviderEventType::Webinar,
            $webinarId,
        );
    }

    public function listWebinarsByTitle(
        string $title,
    ): ProviderWebinarSnapshot {
        return $this->events->listEventsByTitle(
            WebinarProviderEventType::Webinar,
            $title,
        );
    }

    public function getWebinarRecording(
        string $webinarIdOrUuid,
    ): ?ProviderRecordingData {
        return $this->events->getRecording($webinarIdOrUuid);
    }
}