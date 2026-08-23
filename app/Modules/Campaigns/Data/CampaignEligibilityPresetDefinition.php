<?php

namespace App\Modules\Campaigns\Data;

use App\Modules\Campaigns\Models\Campaign;
use InvalidArgumentException;

final class CampaignEligibilityPresetDefinition
{
    /**
     * @param array<string, array<int, string>> $criteria
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $criteria,
        public readonly string $reentryPolicy,
        public readonly string $whenIneligible,
    ) {}

    public static function fromArray(mixed $data): self
    {
        if ($data === null) {
            $data = [];
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException(
                'Campaign eligibility must be an object.',
            );
        }

        self::rejectUnknownFields($data);

        $mode = self::normalizedOption(
            value: $data['mode'] ?? Campaign::ENROLLMENT_MODE_MANUAL,
            allowed: Campaign::ENROLLMENT_MODES,
            label: 'eligibility mode',
        );
        $criteria = self::criteria($data['criteria'] ?? []);
        $reentryPolicy = self::normalizedOption(
            value: $data['reentry'] ?? Campaign::REENTRY_NEVER,
            allowed: Campaign::REENTRY_POLICIES,
            label: 're-entry policy',
        );
        $whenIneligible = self::normalizedOption(
            value: $data['when_ineligible'] ?? Campaign::INELIGIBLE_CONTINUE,
            allowed: Campaign::INELIGIBLE_BEHAVIORS,
            label: 'ineligible behavior',
        );

        if ($mode === Campaign::ENROLLMENT_MODE_AUTOMATIC && $criteria === []) {
            throw new InvalidArgumentException(
                'Automatic Campaign eligibility requires at least one criterion.',
            );
        }

        return new self(
            mode: $mode,
            criteria: $criteria,
            reentryPolicy: $reentryPolicy,
            whenIneligible: $whenIneligible,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'criteria' => $this->criteria,
            'reentry' => $this->reentryPolicy,
            'when_ineligible' => $this->whenIneligible,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function criteria(mixed $data): array
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException(
                'Campaign eligibility criteria must be an object.',
            );
        }

        $criteria = [];

        foreach ($data as $key => $values) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'Campaign eligibility criterion keys must be non-empty strings.',
                );
            }

            if (! is_array($values) || ! array_is_list($values)) {
                throw new InvalidArgumentException(
                    "Campaign eligibility criterion [{$key}] must be a list of stable string values.",
                );
            }

            $normalizedValues = array_values(array_unique(array_map(
                static function (mixed $value) use ($key): string {
                    if (! is_string($value) || trim($value) === '') {
                        throw new InvalidArgumentException(
                            "Campaign eligibility criterion [{$key}] values must be non-empty strings.",
                        );
                    }

                    return trim($value);
                },
                $values,
            )));

            if ($normalizedValues === []) {
                throw new InvalidArgumentException(
                    "Campaign eligibility criterion [{$key}] must contain at least one value.",
                );
            }

            $criteria[self::normalizeSegment($key)] = $normalizedValues;
        }

        ksort($criteria);

        return $criteria;
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function normalizedOption(
        mixed $value,
        array $allowed,
        string $label,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Campaign {$label} must be a non-empty string.",
            );
        }

        $value = self::normalizeSegment($value);

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Unsupported Campaign {$label} [{$value}].",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function rejectUnknownFields(array $data): void
    {
        $unknown = array_values(array_diff(
            array_keys($data),
            ['mode', 'criteria', 'reentry', 'when_ineligible'],
        ));

        if ($unknown === []) {
            return;
        }

        sort($unknown);

        throw new InvalidArgumentException(
            'Campaign eligibility contains unsupported field(s): ['.
            implode(', ', $unknown).'].',
        );
    }

    private static function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}