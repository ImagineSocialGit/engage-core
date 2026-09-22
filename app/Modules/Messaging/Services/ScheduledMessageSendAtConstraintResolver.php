<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\ScheduledMessageSendAtConstraintProvider;
use App\Modules\Messaging\Data\ScheduledMessagePlanningContext;
use Illuminate\Support\Carbon;
use LogicException;

final class ScheduledMessageSendAtConstraintResolver
{
    public function __construct(
        private readonly iterable $providers,
    ) {}

    public function resolve(
        ScheduledMessagePlanningContext $context,
        Carbon $requestedSendAt,
    ): Carbon {
        $resolved = $requestedSendAt->copy()->utc();

        foreach ($this->providers as $provider) {
            if (! $provider instanceof ScheduledMessageSendAtConstraintProvider) {
                throw new LogicException(
                    'Scheduled-message send-time constraint contributors must implement '
                    .ScheduledMessageSendAtConstraintProvider::class.'.',
                );
            }

            $candidate = $provider
                ->constrain($context, $resolved->copy())
                ->copy()
                ->utc();

            if ($candidate->lt($resolved)) {
                throw new LogicException(
                    sprintf(
                        'Scheduled-message send-time constraint [%s] attempted to move a message earlier.',
                        $provider::class,
                    ),
                );
            }

            $resolved = $candidate;
        }

        return $resolved;
    }
}