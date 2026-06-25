<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\SkipScheduledMessagesAction;
use App\Models\WebinarRegistration;
use App\Services\Webinars\WebinarProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelWebinarRegistrationAction
{
    public function __construct(
        private readonly WebinarProviderManager $webinarProviderManager,
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
    ) {}

    public function handle(WebinarRegistration $registration, string $source = 'email_link'): WebinarRegistration
    {
        $registration->loadMissing(['contact', 'webinar']);

        if ($registration->status === 'cancelled') {
            return $registration;
        }

        $this->cancelWithProvider($registration);

        return DB::transaction(function () use ($registration, $source) {
            $meta = $registration->meta ?? [];

            $meta['cancellation'] = [
                'source' => $source,
                'cancelled_at' => now()->toISOString(),
            ];

            $registration->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'meta' => $meta,
            ])->save();

            $this->skipScheduledMessagesAction->forContext(
                context: $registration,
                reason: 'Webinar registration cancelled.',
            );

            return $registration->refresh();
        });
    }

    private function cancelWithProvider(WebinarRegistration $registration): void
    {
        $webinar = $registration->webinar;

        if (! $webinar || blank($webinar->providerKey()) || blank($webinar->external_id)) {
            return;
        }

        try {
            $this->webinarProviderManager
                ->provider($webinar->providerKey())
                ->cancelRegistration($registration);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}