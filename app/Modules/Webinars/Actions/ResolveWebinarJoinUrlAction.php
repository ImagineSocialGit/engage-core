<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;

class ResolveWebinarJoinUrlAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
    ) {}

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
        $this->skipScheduledMessagesAction->forContextMetaValue(
            context: $registration,
            key: 'skip_when_join_clicked',
            value: true,
            reason: 'Registrant clicked join link before live reminder.',
        );
    }
}
