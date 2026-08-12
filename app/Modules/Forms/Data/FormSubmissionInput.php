<?php

namespace App\Modules\Forms\Data;

use InvalidArgumentException;
use JsonException;

final readonly class FormSubmissionInput
{
    public const INTERNAL_META_KEY = '_forms';

    public string $formKey;

    /** @var array<string, mixed> */
    public array $values;

    public string $source;

    public ?string $provider;

    public ?string $externalId;

    /** @var array<string, mixed>|null */
    public ?array $rawPayload;

    /** @var array<string, mixed> */
    public array $meta;

    public ?string $ipAddress;

    public ?string $userAgent;

    public bool $publicOnly;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $rawPayload
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $formKey,
        array $values,
        string $source = 'forms',
        ?string $provider = null,
        ?string $externalId = null,
        ?array $rawPayload = null,
        array $meta = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $publicOnly = false,
    ) {
        $formKey = trim($formKey);

        if (preg_match('/^[a-z][a-z0-9_]*$/', $formKey) !== 1) {
            throw new InvalidArgumentException(
                'Form submission formKey must use lowercase snake_case and begin with a letter.',
            );
        }

        $source = $this->requiredString($source, 'source', 255);
        $provider = $this->nullableString($provider, 'provider', 255, lowercase: true);
        $externalId = $this->nullableString($externalId, 'externalId', 255);

        if (($provider === null) !== ($externalId === null)) {
            throw new InvalidArgumentException(
                'Form submission provider and externalId must either both be present or both be null.',
            );
        }

        if (array_key_exists(self::INTERNAL_META_KEY, $meta)) {
            throw new InvalidArgumentException(
                'Form submission meta key [_forms] is reserved for Forms runtime evidence.',
            );
        }

        $ipAddress = $this->nullableString($ipAddress, 'ipAddress', 45);

        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Form submission ipAddress must be a valid IP address.');
        }

        $this->assertJsonEncodable($values, 'values');
        $this->assertJsonEncodable($rawPayload, 'rawPayload');
        $this->assertJsonEncodable($meta, 'meta');

        $this->formKey = $formKey;
        $this->values = $values;
        $this->source = $source;
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->rawPayload = $rawPayload;
        $this->meta = $meta;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $this->nullableString($userAgent, 'userAgent', 65535);
        $this->publicOnly = $publicOnly;
    }

    public function hasExternalIdentity(): bool
    {
        return $this->provider !== null && $this->externalId !== null;
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Form submission {$label} cannot be empty.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Form submission {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(
        ?string $value,
        string $label,
        int $maximumLength,
        bool $lowercase = false,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Form submission {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $lowercase ? strtolower($value) : $value;
    }

    private function assertJsonEncodable(mixed $value, string $label): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Form submission {$label} must be JSON-encodable.",
                previous: $exception,
            );
        }
    }
}