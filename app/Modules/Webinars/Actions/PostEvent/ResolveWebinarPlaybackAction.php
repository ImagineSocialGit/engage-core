<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Services\WebinarStateCanonicalizer;
use Illuminate\Support\Str;

class ResolveWebinarPlaybackAction
{
    public function __construct(
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly WebinarStateCanonicalizer $stateCanonicalizer,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.recordings.enabled', false)) {
            return true;
        }

        $webinar = $webinar->fresh() ?? $webinar;

        if (filled($webinar->playback_url)) {
            $this->emitReplayAvailableIfNeeded($provider, $webinar, $event);

            return true;
        }

        $recording = $provider->getRecording($webinar);

        if (! $recording || ! $recording->hasPlaybackUrl()) {
            return false;
        }

        $resolvedAt = now()->toIso8601String();

        $webinar->forceFill([
            'playback_token' => $webinar->playback_token ?: Str::random(48),
            'playback_url' => $recording->playbackUrl,
            'playback_passcode' => $recording->playbackPasscode,
            'meta' => array_replace_recursive($webinar->meta ?? [], [
                'normalized' => [
                    'post_event' => $this->stateCanonicalizer
                        ->playbackResolution([
                            'playback_resolved_at' => $resolvedAt,
                        ]),
                ],
            ]),
        ])->save();

        $this->emitReplayAvailableIfNeeded($provider, $webinar->fresh() ?? $webinar, $event);

        return true;
    }

    private function emitReplayAvailableIfNeeded(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): void {
        if (data_get($webinar->meta, 'automation_events.webinar_replay_available_recorded_at')) {
            return;
        }

        if (! filled($webinar->playback_url)) {
            return;
        }

        $this->emitWebinarAutomationEvent->forWebinar(
            eventKey: config('webinars.post_event.automation_events.replay_available.event_key', 'webinar.replay_available'),
            webinar: $webinar,
            occurredAt: now(),
            payload: [
                'provider' => [
                    'key' => $provider->key(),
                ],
                'post_event' => [
                    'event' => $event,
                ],
            ],
        );

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'automation_events' => [
                    'webinar_replay_available_recorded_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();
    }
}