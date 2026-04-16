<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webinars\RecordZoomAttendanceAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ZoomWebhookController extends Controller
{
    public function __invoke(Request $request, RecordZoomAttendanceAction $recordZoomAttendanceAction): JsonResponse
    {
        $payload = $request->all();

        // Zoom endpoint validation handshake can be added here later if needed.

        $event = $payload['event'] ?? null;

        if (! $event) {
            return response()->json(['ok' => true]);
        }

        $recordZoomAttendanceAction->execute($payload);

        return response()->json(['ok' => true]);
    }
}