<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Str;

class ResolveWebinarPlaybackAction
{
    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.recordings.enabled', false)) {
            return true;
        }

        if (filled($webinar->playback_url)) {
            return true;
        }

        $recording = $provider->getRecording($webinar);

        if (! $recording || ! $recording->hasPlaybackUrl()) {
            return false;
        }

        $webinar->forceFill([
            'playback_token' => $webinar->playback_token ?: Str::random(48),
            'playback_url' => $recording->playbackUrl,
            'playback_passcode' => $recording->playbackPasscode,
            'meta' => array_replace_recursive($webinar->meta ?? [], [
                'provider' => [
                    $provider->key() => [
                        'recording' => [
                            'resolved_at' => now()->toIso8601String(),
                            'raw' => $recording->raw,
                        ],
                    ],
                ],
                'normalized' => [
                    'post_event' => [
                        'playback_resolved_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ])->save();

        return true;
    }
}