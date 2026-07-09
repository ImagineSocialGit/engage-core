<?php

namespace App\Support\SetupValidation\Data;

class SetupValidationResult
{
    /**
     * @param array<int, SetupValidationFinding> $findings
     */
    public function __construct(
        private readonly array $findings,
    ) {}

    /**
     * @return array<int, SetupValidationFinding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return array<int, SetupValidationFinding>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (SetupValidationFinding $finding): bool => $finding->isError(),
        ));
    }

    /**
     * @return array<int, SetupValidationFinding>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (SetupValidationFinding $finding): bool => $finding->isWarning(),
        ));
    }

    public function hasErrors(): bool
    {
        return $this->errorCount() > 0;
    }

    public function errorCount(): int
    {
        return count($this->errors());
    }

    public function warningCount(): int
    {
        return count($this->warnings());
    }

    /**
     * @return array{
     *     findings: array<int, array{
     *         severity: string,
     *         code: string,
     *         message: string,
     *         source: string,
     *         path: string|null,
     *         module: string|null,
     *         context: array<string, mixed>,
     *         meta: array<string, mixed>
     *     }>,
     *     error_count: int,
     *     warning_count: int,
     *     has_errors: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'findings' => array_map(
                fn (SetupValidationFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'error_count' => $this->errorCount(),
            'warning_count' => $this->warningCount(),
            'has_errors' => $this->hasErrors(),
        ];
    }
}
