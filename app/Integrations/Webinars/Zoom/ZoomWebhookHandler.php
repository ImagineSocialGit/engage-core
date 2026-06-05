<?php

namespace App\Integrations\Webinars\Zoom;

use App\Actions\Webinars\RecordWebinarAttendanceAction;
use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ZoomWebhookHandler
{
    public function __construct(
        private readonly ZoomWebhookVerifier $verifier,
        private readonly ZoomWebinarService $zoomWebinarService,
        private readonly ZoomAttendanceMapper $zoomAttendanceMapper,
        private readonly RecordWebinarAttendanceAction $recordWebinarAttendanceAction,
    ) {}

    public function handle(Request $request): Response
    {
        if ($request->input('event') === 'endpoint.url_validation') {
            return response()->json(
                $this->verifier->urlValidationResponse($request)
            );
        }

        if (! $this->verifier->hasValidSignature($request)) {
            abort(401);
        }

        $event = $request->input('event');

        if (! in_array($event, [
            'webinar.ended',
            'webinar.completed',
        ], true)) {
            return response()->noContent();
        }

        $webinarId = (string) ($request->input('payload.object.id') ?? '');

        if ($webinarId === '') {
            return response()->noContent();
        }

        $participants = $this->zoomWebinarService
            ->listPastWebinarParticipants($webinarId);

        $attendanceRecords = $this->zoomAttendanceMapper->map($participants);

        $this->recordWebinarAttendanceAction->execute(
            provider: 'zoom',
            externalWebinarId: $webinarId,
            attendanceRecords: $attendanceRecords,
        );

        return response()->noContent();
    }
}
