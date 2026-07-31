<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

final class MessageDefinitionConfigSetResolver
{
    public const DEFAULT_TEMPLATE_SET_KEY = 'default';

    /**
     * @param array<string|int, mixed> $scopeConfig
     * @return array<int, array{
     *     key: string|null,
     *     source_key: string|null,
     *     definitions: array<string|int, mixed>
     * }>
     */
    public function sets(string $scope, array $scopeConfig): array
    {
        if ($this->normalizeSegment($scope) !== 'webinar') {
            return [[
                'key' => null,
                'source_key' => null,
                'definitions' => $scopeConfig,
            ]];
        }

        $flatDefinitions = [];
        $sets = [];

        foreach ($scopeConfig as $key => $value) {
            if ($this->isFlatDefinitionGroup($key, $value)) {
                $flatDefinitions[$key] = $value;

                continue;
            }

            if (! is_string($key) || trim($key) === '' || ! is_array($value)) {
                $flatDefinitions[$key] = $value;

                continue;
            }

            $normalizedKey = $this->normalizeSegment($key);

            if (array_key_exists($normalizedKey, $sets)) {
                throw new InvalidArgumentException(
                    "Duplicate normalized Webinar message template set key [{$normalizedKey}].",
                );
            }

            $sets[$normalizedKey] = [
                'key' => $normalizedKey,
                'source_key' => trim($key),
                'definitions' => $value,
            ];
        }

        if ($flatDefinitions !== []) {
            if (array_key_exists(self::DEFAULT_TEMPLATE_SET_KEY, $sets)) {
                throw new InvalidArgumentException(
                    'Webinar message definitions cannot combine flat default definitions with a nested [default] template set.',
                );
            }

            $sets = [
                self::DEFAULT_TEMPLATE_SET_KEY => [
                    'key' => self::DEFAULT_TEMPLATE_SET_KEY,
                    'source_key' => null,
                    'definitions' => $flatDefinitions,
                ],
                ...$sets,
            ];
        }

        return array_values($sets);
    }

    public function assignmentDefinitionKey(
        ?string $templateSetKey,
        string $definitionKey,
    ): string {
        $definitionKey = $this->normalizeSegment($definitionKey);
        $templateSetKey = $this->normalizeNullableSegment($templateSetKey);

        if (
            $templateSetKey === null
            || $templateSetKey === self::DEFAULT_TEMPLATE_SET_KEY
        ) {
            return $definitionKey;
        }

        return $templateSetKey.'.'.$definitionKey;
    }

    public function leafDefinitionKey(string $definitionKey): string
    {
        $definitionKey = trim($definitionKey);
        $lastSeparator = strrpos($definitionKey, '.');

        return $this->normalizeSegment(
            $lastSeparator === false
                ? $definitionKey
                : substr($definitionKey, $lastSeparator + 1),
        );
    }

    private function isFlatDefinitionGroup(
        string|int $key,
        mixed $value,
    ): bool {
        if ($key === 'campaigns') {
            return true;
        }

        if (! is_array($value)) {
            return true;
        }

        if (array_is_list($value)) {
            return true;
        }

        foreach ([
            'channel',
            'dispatch_key',
            'dispatch_keys',
            'message_type',
            'payload',
            'payload_class',
            'purpose',
            'queue',
            'scope',
        ] as $definitionField) {
            if (array_key_exists($definitionField, $value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeNullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}