<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelWebinarRegistrationAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly QueueWebinarProviderCancellationAction $queueProviderCancellation,
    ) {}

    public function handle(WebinarRegistration $registration, string $source = 'email_link'): WebinarRegistration
    {
        $registration = DB::transaction(function () use ($registration, $source): WebinarRegistration {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $locked->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $cancelledAt = now();
            $meta = is_array($locked->meta) ? $locked->meta : [];

            $meta['cancellation'] = [
                'source' => $source,
                'cancelled_at' => $cancelledAt->toISOString(),
            ];

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'meta' => $meta,
            ])->save();

            $this->skipScheduledMessagesAction->forContext(
                context: $locked,
                reason: 'Webinar registration cancelled.',
            );

            DB::afterCommit(function () use ($locked, $cancelledAt, $source): void {
                $committed = $locked->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]);

                if (! $committed instanceof WebinarRegistration) {
                    return;
                }

                try {
                    $this->emitWebinarAutomationEvent->forRegistration(
                        eventKey: 'webinar.cancelled',
                        registration: $committed,
                        occurredAt: $committed->cancelled_at ?? $cancelledAt,
                        payload: [
                            'cancellation' => [
                                'source' => $source,
                            ],
                        ],
                    );
                } catch (Throwable $exception) {
                    // Automation is downstream of the committed cancellation.
                    report($exception);
                }
            });

            return $locked;
        });

        try {
            $this->queueProviderCancellation->handle($registration);
        } catch (Throwable $exception) {
            // Provider reconciliation is downstream of the committed local
            // cancellation and must not change the public cancellation result.
            report($exception);
        }

        return $registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]) ?? $registration;
    }
}
