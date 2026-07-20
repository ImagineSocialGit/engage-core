<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;

class ResolveWebinarJoinUrlAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
    ) {}

    /**
     * Resolve the current destination without recording a trusted interaction.
     *
     * This method is safe for public GET and HEAD requests, including scanners,
     * previews, and prefetchers.
     */
    public function handle(WebinarRegistration $registration): ?string
    {
        $registration->loadMissing('webinar');

        return $this->destinationFor($registration);
    }

    /**
     * Resolve the destination and atomically record an explicitly trusted join
     * interaction before suppressing eligible pending live reminders.
     */
    public function execute(WebinarRegistration $registration): ?string
    {
        return DB::transaction(function () use ($registration): ?string {
            $locked = WebinarRegistration::query()
                ->with('webinar')
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $destination = $this->destinationFor($locked);

            if (blank($destination)) {
                return null;
            }

            $this->markTrustedJoinInteraction($locked);
            $this->skipJoinClickedMessages($locked);

            return $destination;
        });
    }

    private function destinationFor(WebinarRegistration $registration): ?string
    {
        if (
            $registration->status === 'cancelled'
            || $registration->cancelled_at !== null
        ) {
            return null;
        }

        $destination = data_get($registration->meta, 'provider.join_url')
            ?: $registration->webinar?->join_url;

        return filled($destination)
            ? trim((string) $destination)
            : null;
    }

    private function markTrustedJoinInteraction(
        WebinarRegistration $registration,
    ): void {
        $meta = is_array($registration->meta)
            ? $registration->meta
            : [];
        $recordedAt = now()->toISOString();
        $existingCount = (int) ($meta['join_click_count'] ?? 0);
        $interaction = is_array($meta['join_interaction'] ?? null)
            ? $meta['join_interaction']
            : [];
        $firstConfirmedAt = $interaction['first_confirmed_at']
            ?? $meta['join_clicked_at']
            ?? $recordedAt;

        $meta['join_clicked_at'] = $recordedAt;
        $meta['join_click_count'] = $existingCount + 1;
        $meta['join_interaction'] = array_replace($interaction, [
            'source' => 'public_signed_post',
            'first_confirmed_at' => $firstConfirmedAt,
            'last_confirmed_at' => $recordedAt,
            'confirmed_count' => $existingCount + 1,
        ]);

        $registration->forceFill([
            'meta' => $meta,
        ])->save();
    }

    private function skipJoinClickedMessages(
        WebinarRegistration $registration,
    ): void {
        $this->skipScheduledMessagesAction->forContextMetaValue(
            context: $registration,
            key: 'skip_when_join_clicked',
            value: true,
            reason: 'Registrant confirmed the join action before the live reminder.',
        );
    }
}