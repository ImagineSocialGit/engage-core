<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelWebinarRegistrationAction
{
    public function __construct(
        private readonly WebinarProviderManager $webinarProviderManager,
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ) {}

    public function handle(WebinarRegistration $registration, string $source = 'email_link'): WebinarRegistration
    {
        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        if ($registration->status === 'cancelled') {
            return $registration;
        }

        $this->cancelWithProvider($registration);

        return DB::transaction(function () use ($registration, $source) {
            $cancelledAt = now();

            $meta = $registration->meta ?? [];

            $meta['cancellation'] = [
                'source' => $source,
                'cancelled_at' => $cancelledAt->toISOString(),
            ];

            $registration->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'meta' => $meta,
            ])->save();

            $this->skipScheduledMessagesAction->forContext(
                context: $registration,
                reason: 'Webinar registration cancelled.',
            );

            DB::afterCommit(function () use ($registration, $cancelledAt, $source) {
                $registration = $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]);

                if (! $registration) {
                    return;
                }

                $this->emitWebinarAutomationEvent->forRegistration(
                    eventKey: 'webinar.cancelled',
                    registration: $registration,
                    occurredAt: $registration->cancelled_at ?? $cancelledAt,
                    payload: [
                        'cancellation' => [
                            'source' => $source,
                        ],
                    ],
                );
            });

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