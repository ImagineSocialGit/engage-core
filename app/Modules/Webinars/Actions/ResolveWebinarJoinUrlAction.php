<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\WebinarRegistration;

class ResolveWebinarJoinUrlAction
{
    public function execute(WebinarRegistration $registration): ?string
    {
        $registration->loadMissing('webinar');

        $destination = data_get($registration->meta, 'provider.join_url')
            ?: $registration->webinar?->join_url;

        if (blank($destination)) {
            return null;
        }

        $this->markJoinClicked($registration);
        $this->skipJoinClickedMessages($registration);

        return $destination;
    }

    private function markJoinClicked(WebinarRegistration $registration): void
    {
        $meta = $registration->meta ?? [];

        $meta['join_clicked_at'] = now()->toISOString();
        $meta['join_click_count'] = ((int) ($meta['join_click_count'] ?? 0)) + 1;

        $registration->forceFill([
            'meta' => $meta,
        ])->save();
    }

    private function skipJoinClickedMessages(WebinarRegistration $registration): void
    {
        ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->where('status', 'pending')
            ->where('message_type', 'reminder')
            ->get()
            ->filter(fn (ScheduledMessage $message): bool => data_get($message->meta, 'skip_when_join_clicked') === true)
            ->each(function (ScheduledMessage $message): void {
                $message->forceFill([
                    'status' => 'skipped',
                    'skipped_at' => now(),
                    'skip_reason' => 'Registrant clicked join link before live reminder.',
                    'failure_reason' => null,
                ])->save();
            });
    }
}