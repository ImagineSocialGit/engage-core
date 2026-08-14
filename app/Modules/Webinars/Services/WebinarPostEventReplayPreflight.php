<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebinarPostEventReplayPreflight
{
    public function __construct(
        private readonly WebinarProviderManager $providerManager,
    ) {}

    public function denialReason(Webinar $webinar): ?string
    {
        $webinar = $webinar->fresh() ?? $webinar;
        $review = data_get($webinar->meta, 'normalized.post_event.review', []);
        $review = is_array($review) ? $review : [];
        $status = $review['status'] ?? null;

        if (config('webinars.post_event.review.required', false)) {
            if ($status === 'suppressed' || ($review['playback_mode'] ?? null) === 'none') {
                return 'webinar_replay_suppressed';
            }

            if ($status !== 'approved') {
                return 'webinar_post_event_review_pending';
            }
        }

        if ($status === 'suppressed' || ($review['playback_mode'] ?? null) === 'none') {
            return 'webinar_replay_suppressed';
        }

        $sourceWebinar = $this->sourceWebinar($webinar, $review);

        if (! $sourceWebinar) {
            return 'webinar_recording_unavailable';
        }

        $provider = $this->providerManager->forWebinar($sourceWebinar);
        $recording = $provider->getRecording($sourceWebinar);

        if (! $recording || ! $recording->hasPlaybackUrl()) {
            return 'webinar_recording_unavailable';
        }

        if (
            $webinar->playback_url !== $recording->playbackUrl
            || $webinar->playback_passcode !== $recording->playbackPasscode
            || blank($webinar->playback_token)
        ) {
            DB::transaction(function () use ($webinar, $recording): void {
                $locked = Webinar::query()
                    ->lockForUpdate()
                    ->findOrFail($webinar->getKey());

                $locked->forceFill([
                    'playback_token' => $locked->playback_token ?: Str::random(48),
                    'playback_url' => $recording->playbackUrl,
                    'playback_passcode' => $recording->playbackPasscode,
                ])->save();
            });

            $webinar->refresh();
        }

        return null;
    }

    /** @param array<string, mixed> $review */
    private function sourceWebinar(Webinar $webinar, array $review): ?Webinar
    {
        $sourceId = $review['source_webinar_id'] ?? null;

        if (! is_numeric($sourceId) || (int) $sourceId <= 0) {
            return $webinar;
        }

        $source = Webinar::query()->find((int) $sourceId);

        if (! $source) {
            return null;
        }

        if ((int) $source->webinar_series_id !== (int) $webinar->webinar_series_id) {
            return null;
        }

        if (! $source->ends_at || $source->ends_at->isFuture()) {
            return null;
        }

        return $source;
    }
}