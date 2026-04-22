<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webinars\RecordZoomAttendanceAction;
use App\Http\Controllers\Controller;
use App\Services\Zoom\ZoomWebinarService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ZoomWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ZoomWebinarService $zoomWebinarService,
        RecordZoomAttendanceAction $recordZoomAttendanceAction
    ): Response {
        $event = $request->input('event');

        if (! in_array($event, [
            'webinar.ended',
            'webinar.completed',
        ], true)) {
            return response()->noContent();
        }

        $webinarId = (string) (
            $request->input('payload.object.id')
            ?? ''
        );

        if ($webinarId === '') {
            return response()->noContent();
        }

        $participants = $zoomWebinarService
            ->listPastWebinarParticipants($webinarId);

        $recordZoomAttendanceAction->execute(
            $webinarId,
            $participants
        );

        return response()->noContent();
    }
}