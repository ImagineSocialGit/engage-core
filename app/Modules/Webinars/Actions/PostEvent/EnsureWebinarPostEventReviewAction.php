<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Facades\DB;

class EnsureWebinarPostEventReviewAction
{
    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.review.required', false)) {
            return true;
        }

        DB::transaction(function () use ($webinar, $event): void {
            $locked = Webinar::query()
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $review = data_get($meta, 'normalized.post_event.review', []);
            $review = is_array($review) ? $review : [];
            $status = $review['status'] ?? null;

            if (in_array($status, ['approved', 'suppressed'], true)) {
                return;
            }

            data_set($meta, 'normalized.post_event.review', array_replace($review, [
                'status' => 'pending',
                'requested_at' => $review['requested_at'] ?? now()->toIso8601String(),
                'last_event' => $event,
                'reviewed_at' => null,
                'reviewed_by_user_id' => null,
            ]));

            $locked->forceFill(['meta' => $meta])->save();
        });

        return true;
    }
}