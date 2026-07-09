<?php

namespace App\Support\SetupValidation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\Data\SetupValidationResult;
use InvalidArgumentException;

class SetupValidationManager
{
    /**
     * @param iterable<int, SetupValidationContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    public function validate(): SetupValidationResult
    {
        $findings = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof SetupValidationContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Setup validation manager received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            foreach ($contributor->findings() as $finding) {
                if (! $finding instanceof SetupValidationFinding) {
                    throw new InvalidArgumentException(sprintf(
                        'Setup validation contributor [%s] returned invalid finding [%s].',
                        $contributor::class,
                        get_debug_type($finding),
                    ));
                }

                $findings[] = $finding;
            }
        }

        usort($findings, fn (
            SetupValidationFinding $left,
            SetupValidationFinding $right,
        ): int => $this->compareFindings($left, $right));

        return new SetupValidationResult($findings);
    }

    private function compareFindings(
        SetupValidationFinding $left,
        SetupValidationFinding $right,
    ): int {
        return [
            $this->severityRank($left->severity),
            $left->module ?? '',
            $left->source,
            $left->path ?? '',
            $left->code,
            $left->message,
        ] <=> [
            $this->severityRank($right->severity),
            $right->module ?? '',
            $right->source,
            $right->path ?? '',
            $right->code,
            $right->message,
        ];
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            SetupValidationFinding::SEVERITY_ERROR => 0,
            SetupValidationFinding::SEVERITY_WARNING => 1,
            default => 2,
        };
    }
}
