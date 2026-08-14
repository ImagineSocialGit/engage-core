<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Services\WebinarProviderManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewWebinarPostEventAction
{
    public const MODE_CURRENT = 'current';
    public const MODE_ALTERNATE = 'alternate';
    public const MODE_NONE = 'none';

    public function __construct(
        private readonly WebinarProviderManager $providerManager,
    ) {}

    public function handle(
        Webinar $webinar,
        string $playbackMode,
        ?Webinar $alternateWebinar = null,
        ?int $reviewedByUserId = null,
    ): Webinar {
        $playbackMode = strtolower(trim($playbackMode));

        if (! in_array($playbackMode, [
            self::MODE_CURRENT,
            self::MODE_ALTERNATE,
            self::MODE_NONE,
        ], true)) {
            throw new InvalidArgumentException('Unsupported post-event playback choice.');
        }

        $webinar = $webinar->fresh() ?? $webinar;

        if (
            $playbackMode !== self::MODE_NONE
            && config('webinars.post_event.attendance.enabled', false)
            && ! data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')
        ) {
            throw new DomainException(
                'Attendance is still syncing. Wait for attendance to finish before approving a replay.'
            );
        }

        if ($playbackMode === self::MODE_NONE) {
            return $this->recordDecision(
                webinar: $webinar,
                status: 'suppressed',
                playbackMode: self::MODE_NONE,
                sourceWebinar: null,
                reviewedByUserId: $reviewedByUserId,
            );
        }

        $sourceWebinar = $playbackMode === self::MODE_ALTERNATE
            ? $this->validatedAlternate($webinar, $alternateWebinar)
            : ($webinar->fresh() ?? $webinar);

        $provider = $this->providerManager->forWebinar($sourceWebinar);
        $recording = $provider->getRecording($sourceWebinar);

        if (! $recording || ! $recording->hasPlaybackUrl()) {
            throw new DomainException(
                'The selected replay is not currently available from the webinar provider.'
            );
        }

        return DB::transaction(function () use (
            $webinar,
            $playbackMode,
            $sourceWebinar,
            $reviewedByUserId,
            $recording,
        ): Webinar {
            $locked = Webinar::query()
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $review = data_get($meta, 'normalized.post_event.review', []);
            $review = is_array($review) ? $review : [];

            data_set($meta, 'normalized.post_event.review', array_replace($review, [
                'status' => 'approved',
                'playback_mode' => $playbackMode,
                'source_webinar_id' => (int) $sourceWebinar->getKey(),
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by_user_id' => $reviewedByUserId,
            ]));

            if (data_get($meta, 'normalized.post_event.follow_up_summary.reason') === 'webinar_replay_suppressed') {
                data_forget($meta, 'normalized.post_event.follow_ups_dispatched_at');
                data_forget($meta, 'normalized.post_event.follow_up_summary');
            }

            $locked->forceFill([
                'playback_token' => $locked->playback_token ?: Str::random(48),
                'playback_url' => $recording->playbackUrl,
                'playback_passcode' => $recording->playbackPasscode,
                'meta' => $meta,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    private function validatedAlternate(
        Webinar $webinar,
        ?Webinar $alternateWebinar,
    ): Webinar {
        if (! $alternateWebinar) {
            throw new InvalidArgumentException('Choose a previous webinar replay.');
        }

        $alternateWebinar = $alternateWebinar->fresh() ?? $alternateWebinar;

        if ($alternateWebinar->is($webinar)) {
            throw new InvalidArgumentException('Choose a different webinar replay.');
        }

        if ((int) $alternateWebinar->webinar_series_id !== (int) $webinar->webinar_series_id) {
            throw new InvalidArgumentException(
                'The alternate replay must belong to the same webinar series.'
            );
        }

        if (! $alternateWebinar->ends_at || $alternateWebinar->ends_at->isFuture()) {
            throw new InvalidArgumentException(
                'The alternate replay must come from a completed webinar.'
            );
        }

        return $alternateWebinar;
    }

    private function recordDecision(
        Webinar $webinar,
        string $status,
        string $playbackMode,
        ?Webinar $sourceWebinar,
        ?int $reviewedByUserId,
    ): Webinar {
        return DB::transaction(function () use (
            $webinar,
            $status,
            $playbackMode,
            $sourceWebinar,
            $reviewedByUserId,
        ): Webinar {
            $locked = Webinar::query()
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $review = data_get($meta, 'normalized.post_event.review', []);
            $review = is_array($review) ? $review : [];

            data_set($meta, 'normalized.post_event.review', array_replace($review, [
                'status' => $status,
                'playback_mode' => $playbackMode,
                'source_webinar_id' => $sourceWebinar?->getKey(),
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by_user_id' => $reviewedByUserId,
            ]));

            $locked->forceFill(['meta' => $meta])->save();

            return $locked->fresh() ?? $locked;
        });
    }
}