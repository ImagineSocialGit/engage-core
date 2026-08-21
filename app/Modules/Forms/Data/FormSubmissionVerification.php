<?php

namespace App\Modules\Forms\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class FormSubmissionVerification
{
    public const OUTCOME_PASSED = 'passed';

    private const ID_PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    public string $provider;

    public string $outcome;

    public string $verifiedAt;

    public ?string $hostname;

    public ?string $action;

    public string $authenticatedClientId;

    public function __construct(
        string $provider,
        string $outcome,
        string $verifiedAt,
        ?string $hostname,
        ?string $action,
        string $authenticatedClientId,
    ) {
        $provider = $this->identifier(
            $provider,
            'provider',
            64,
        );
        $outcome = strtolower(trim($outcome));

        if ($outcome !== self::OUTCOME_PASSED) {
            throw new InvalidArgumentException(
                'Form submission verification outcome must be [passed].',
            );
        }

        $this->provider = $provider;
        $this->outcome = $outcome;
        $this->verifiedAt = $this->timestamp($verifiedAt);
        $this->hostname = $this->hostname($hostname);
        $this->action = $this->action($action);
        $this->authenticatedClientId = $this->identifier(
            $authenticatedClientId,
            'authenticatedClientId',
            255,
        );
    }

    /**
     * @return array{
     *     version: int,
     *     provider: string,
     *     outcome: string,
     *     verified_at: string,
     *     hostname: string|null,
     *     action: string|null,
     *     authenticated_client_id: string
     * }
     */
    public function evidence(): array
    {
        return [
            'version' => 1,
            'provider' => $this->provider,
            'outcome' => $this->outcome,
            'verified_at' => $this->verifiedAt,
            'hostname' => $this->hostname,
            'action' => $this->action,
            'authenticated_client_id' => $this->authenticatedClientId,
        ];
    }

    private function identifier(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = strtolower(trim($value));

        if ($value === ''
            || mb_strlen($value) > $maximumLength
            || preg_match(self::ID_PATTERN, $value) !== 1
        ) {
            throw new InvalidArgumentException(
                "Form submission verification {$label} must be a lowercase provider/client identifier.",
            );
        }

        return $value;
    }

    private function timestamp(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) > 64
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Form submission verification verifiedAt must be an RFC3339 timestamp with an explicit timezone.',
            );
        }

        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Form submission verification verifiedAt must be a valid timestamp.',
                previous: $exception,
            );
        }

        return $timestamp
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:sP');
    }

    private function hostname(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 253
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Form submission verification hostname must be a valid hostname.',
            );
        }

        return $value;
    }

    private function action(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 64
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                'Form submission verification action must use letters, numbers, dots, underscores, or hyphens.',
            );
        }

        return $value;
    }
}