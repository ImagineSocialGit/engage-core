<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationCancellationPolicy;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class CancelWebinarRegistrationAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly QueueWebinarProviderCancellationAction $queueProviderCancellation,
        private readonly ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        private readonly WebinarRegistrationCancellationPolicy $cancellationPolicy,
    ) {}

    public function handle(
        WebinarRegistration $registration,
        string $source = 'email_link',
    ): WebinarRegistration {
        [$registration, $wasCancelled] = DB::transaction(function () use ($registration, $source): array {
            $chain = $this->resolveReplacementChain->handle(
                registration: $registration,
                lock: true,
            );

            if (! $chain->safeForPublicLifecycle()) {
                throw new LogicException(
                    'Webinar registration cancellation cannot continue through an invalid replacement chain.',
                );
            }

            $locked = $chain->canonical;
            $cancellationState = $this->cancellationPolicy
                ->assertCancellableOrAlreadyCancelled($locked);

            if (
                $cancellationState
                === WebinarRegistrationCancellationPolicy::STATE_ALREADY_CANCELLED
            ) {
                return [$locked, false];
            }

            $cancelledAt = now();
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $existingCancellation = is_array($meta['cancellation'] ?? null)
                ? $meta['cancellation']
                : [];

            $meta['cancellation'] = array_replace($existingCancellation, [
                'source' => $source,
                'cancelled_at' => $cancelledAt->toISOString(),
                'resolved_from_registration_id' => (int) $chain->original->getKey(),
                'canonical_registration_id' => (int) $locked->getKey(),
                'traversed_registration_ids' => $chain->traversedRegistrationIds,
            ]);

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'meta' => $meta,
            ])->save();

            $this->skipScheduledMessagesAction->forContext(
                context: $locked,
                reason: 'Webinar registration cancelled.',
            );

            $this->emitWebinarAutomationEvent->forRegistration(
                eventKey: 'webinar.cancelled',
                registration: $locked,
                occurredAt: $locked->cancelled_at ?? $cancelledAt,
                payload: [
                    'cancellation' => [
                        'source' => $source,
                        'resolved_from_registration_id' => (int) $chain->original->getKey(),
                        'canonical_registration_id' => (int) $locked->getKey(),
                        'traversed_registration_ids' => $chain->traversedRegistrationIds,
                    ],
                ],
            );

            return [$locked, true];
        });

        if ($wasCancelled) {
            try {
                $this->queueProviderCancellation->handle($registration);
            } catch (Throwable $exception) {
                // Provider reconciliation is downstream of the committed local
                // cancellation and must not change the public cancellation result.
                report($exception);
            }
        }

        return $registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]) ?? $registration;
    }
}