<?php

namespace App\Support\AutomationOpportunities\Services;

use InvalidArgumentException;
use JsonException;

class AutomationBehaviorFingerprintBuilder
{
    /**
     * @param array<string, mixed> $parts
     */
    public function build(array $parts): string
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Automation behavior fingerprint parts cannot be empty.');
        }

        try {
            $serialized = json_encode(
                $this->normalize($parts),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Automation behavior fingerprint parts could not be serialized.',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
