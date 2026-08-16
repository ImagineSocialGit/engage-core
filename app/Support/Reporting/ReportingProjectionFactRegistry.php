<?php

namespace App\Support\Reporting;

use App\Support\Reporting\Contracts\ReportingProjectionFactContributor;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use InvalidArgumentException;

class ReportingProjectionFactRegistry
{
    /** @var array<string, ReportingProjectionFactContributor> */
    private array $contributors = [];

    /**
     * @param iterable<int, mixed> $contributors
     */
    public function __construct(iterable $contributors = [])
    {
        foreach ($contributors as $contributor) {
            if (! $contributor instanceof ReportingProjectionFactContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Reporting projection fact contributors must implement [%s].',
                    ReportingProjectionFactContributor::class,
                ));
            }

            $key = trim($contributor->key());

            if ($key === ''
                || strlen($key) > 100
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Reporting projection fact contributor keys must be bounded lowercase identifiers.',
                );
            }

            if (isset($this->contributors[$key])) {
                throw new InvalidArgumentException(
                    "Duplicate Reporting projection fact contributor [{$key}].",
                );
            }

            $this->contributors[$key] = $contributor;
        }

        ksort($this->contributors);
    }

    /**
     * @return array<string, ReportingProjectionFactContributor>
     */
    public function contributors(): array
    {
        return $this->contributors;
    }

    /**
     * @return iterable<int, ReportingProjectionFact>
     */
    public function facts(ReportingProjectionWindow $window): iterable
    {
        foreach ($this->contributors as $contributorKey => $contributor) {
            foreach ($contributor->facts($window) as $fact) {
                if (! $fact instanceof ReportingProjectionFact) {
                    throw new InvalidArgumentException(
                        "Reporting projection contributor [{$contributorKey}] yielded an invalid fact.",
                    );
                }

                if ($fact->occurredAt->lessThan($window->startsAt)
                    || $fact->occurredAt->greaterThan($window->endsAt)
                ) {
                    throw new InvalidArgumentException(
                        "Reporting projection contributor [{$contributorKey}] yielded a fact outside the requested window.",
                    );
                }

                yield $fact;
            }
        }
    }
}