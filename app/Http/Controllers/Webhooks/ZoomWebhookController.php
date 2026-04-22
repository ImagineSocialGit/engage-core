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

        if ($request->input('event') === 'endpoint.url_validation') {
            return response()->json([
                'plainToken' => $request->input('payload.plainToken'),
                'encryptedToken' => hash_hmac(
                    'sha256',
                    (string) $request->input('payload.plainToken'),
                    config('services.zoom.webhook_secret')
                ),
            ]);
        }
        
        if (! $this->hasValidSignature($request)) {
            abort(401);
        }

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

    private function hasValidSignature(Request $request): bool
    {
        $secret = config('services.zoom.webhook_secret');

        if (! filled($secret)) {
            return false;
        }

        $timestamp = (string) $request->header('x-zm-request-timestamp');
        $signature = (string) $request->header('x-zm-signature');

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        $message = 'v0:' . $timestamp . ':' . $request->getContent();

        $expected = 'v0=' . hash_hmac(
            'sha256',
            $message,
            $secret
        );

        return hash_equals($expected, $signature);
    }
}