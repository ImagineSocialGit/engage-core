<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class WebinarRecordingAvailabilityResolver
{
    public function __construct(private readonly WebinarProviderManager $providers) {}

    public function exists(Webinar $webinar, string $activation, string $dueAt): bool
    {
        return Cache::lock('webinar:plan:recording:'.$webinar->getKey(), 120)
            ->block(30, function () use ($webinar, $activation, $dueAt): bool {
                $webinar->refresh();
                $meta = is_array($webinar->meta) ? $webinar->meta : [];
                $decisionKey = sha1($activation.'|'.$dueAt);
                $decision = data_get($meta, 'post_event_plan.recording_decisions.'.$decisionKey);

                if (is_array($decision)) {
                    return ($decision['available'] ?? false) === true;
                }

                $review = data_get($meta, 'normalized.post_event.review', []);
                $suppressed = data_get($review, 'status') === 'suppressed'
                    || data_get($review, 'playback_mode') === 'none';
                $available = false;

                if (! $suppressed) {
                    if (filled($webinar->playback_url)) {
                        $available = true;
                    } else {
                        $recording = $this->providers->forWebinar($webinar)->getRecording($webinar);
                        if ($recording?->hasPlaybackUrl()) {
                            $webinar->playback_token = $webinar->playback_token ?: Str::random(48);
                            $webinar->playback_url = $recording->playbackUrl;
                            $webinar->playback_passcode = $recording->playbackPasscode;
                            $available = true;
                        }
                    }
                }

                data_set($meta, 'post_event_plan.recording_decisions.'.$decisionKey, [
                    'available' => $available,
                    'checked_at' => now()->toIso8601String(),
                ]);
                $webinar->meta = $meta;
                $webinar->save();

                return $available;
            });
    }
}