<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Contracts\EventReadinessContributor;
use App\Modules\Events\Data\EventReadinessFinding;
use App\Modules\Events\Data\EventReadinessResult;
use App\Modules\Events\Models\Event;
use InvalidArgumentException;

final class EventReadinessRegistry
{
    public const CONTRIBUTOR_TAG = 'events.readiness_contributors';

    /**
     * @param iterable<int, EventReadinessContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors = [],
    ) {}

    public function evaluate(Event $event, string $capability): EventReadinessResult
    {
        $capability = $this->normalizeCapability($capability);
        $findings = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof EventReadinessContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Event readiness contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    EventReadinessContributor::class,
                ));
            }

            if ($this->normalizeCapability($contributor->capability()) !== $capability) {
                continue;
            }

            foreach ($contributor->findings($event) as $finding) {
                if (! $finding instanceof EventReadinessFinding) {
                    throw new InvalidArgumentException(sprintf(
                        'Event readiness contributor [%s] returned [%s]; expected [%s].',
                        $contributor::class,
                        get_debug_type($finding),
                        EventReadinessFinding::class,
                    ));
                }

                $findings[] = $finding;
            }
        }

        return new EventReadinessResult(
            capability: $capability,
            findings: $findings,
        );
    }

    /**
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof EventReadinessContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Event readiness contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    EventReadinessContributor::class,
                ));
            }

            $capabilities[] = $this->normalizeCapability($contributor->capability());
        }

        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);

        return $capabilities;
    }

    private function normalizeCapability(string $capability): string
    {
        $capability = trim($capability);

        if ($capability === ''
            || ! preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $capability)
        ) {
            throw new InvalidArgumentException(
                "Event readiness capability [{$capability}] must use lowercase snake_case.",
            );
        }

        return $capability;
    }
}