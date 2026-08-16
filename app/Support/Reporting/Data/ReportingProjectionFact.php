<?php

namespace App\Support\Reporting\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportingProjectionFact
{
    /**
     * @param array<string, scalar|null> $dimensions
     * @param array<string, scalar|null> $values
     */
    public function __construct(
        public string $key,
        public int $version,
        public CarbonImmutable $occurredAt,
        public string $subjectType,
        public string $subjectId,
        public ?string $correlationId = null,
        public array $dimensions = [],
        public array $values = [],
    ) {
        $this->assertIdentifier($this->key, 'fact key', 100);

        if ($this->version < 1 || $this->version > 65535) {
            throw new InvalidArgumentException(
                'Reporting projection fact version must be between 1 and 65535.',
            );
        }

        $this->assertBoundedString($this->subjectType, 'subject type', 191);
        $this->assertBoundedString($this->subjectId, 'subject id', 191);

        if ($this->correlationId !== null) {
            $this->assertBoundedString($this->correlationId, 'correlation id', 191);
        }

        $this->assertScalarMap($this->dimensions, 'dimensions');
        $this->assertScalarMap($this->values, 'values');
    }

    private function assertIdentifier(
        string $value,
        string $label,
        int $maxLength,
    ): void {
        $this->assertBoundedString($value, $label, $maxLength);

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Reporting projection {$label} must be a lowercase identifier.",
            );
        }
    }

    private function assertBoundedString(
        string $value,
        string $label,
        int $maxLength,
    ): void {
        if (trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "Reporting projection {$label} must be non-empty and no longer than {$maxLength} characters.",
            );
        }
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function assertScalarMap(array $values, string $label): void
    {
        if (count($values) > 32) {
            throw new InvalidArgumentException(
                "Reporting projection {$label} cannot contain more than 32 entries.",
            );
        }

        foreach ($values as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $key) !== 1
            ) {
                throw new InvalidArgumentException(
                    "Reporting projection {$label} keys must be bounded lowercase identifiers.",
                );
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(
                    "Reporting projection {$label} values must be scalar or null.",
                );
            }

            if (is_string($value) && mb_strlen($value) > 255) {
                throw new InvalidArgumentException(
                    "Reporting projection {$label} string values cannot exceed 255 characters.",
                );
            }
        }
    }
}