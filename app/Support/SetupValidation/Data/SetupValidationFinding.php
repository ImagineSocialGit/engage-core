<?php

namespace App\Support\SetupValidation\Data;

use InvalidArgumentException;

class SetupValidationFinding
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    public const SEVERITIES = [
        self::SEVERITY_ERROR,
        self::SEVERITY_WARNING,
    ];

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $source,
        public readonly ?string $path = null,
        public readonly ?string $module = null,
        public readonly array $context = [],
        public readonly array $meta = [],
    ) {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException("Unsupported setup validation severity [{$severity}].");
        }

        foreach ([
            'code' => $code,
            'message' => $message,
            'source' => $source,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    "Setup validation finding [{$field}] must be a non-empty string."
                );
            }
        }
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    /**
     * @return array{
     *     severity: string,
     *     code: string,
     *     message: string,
     *     source: string,
     *     path: string|null,
     *     module: string|null,
     *     context: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'source' => $this->source,
            'path' => $this->path,
            'module' => $this->module,
            'context' => $this->context,
            'meta' => $this->meta,
        ];
    }
}
