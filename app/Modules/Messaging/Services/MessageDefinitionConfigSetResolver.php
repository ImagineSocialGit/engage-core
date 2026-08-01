<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

final class MessageDefinitionConfigSetResolver
{
    public const DEFAULT_TEMPLATE_SET_KEY = 'default';

    private const DEFINITION_FIELDS = [
        'channel',
        'dispatch_key',
        'dispatch_keys',
        'message_type',
        'payload',
        'payload_class',
        'purpose',
        'queue',
        'scope',
    ];

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

        if ($scopeConfig === []) {
            return [];
        }

        $sets = [];

        foreach ($scopeConfig as $key => $definitions) {
            if (! $this->isNamedSet($key, $definitions)) {
                throw new InvalidArgumentException(
                    'Webinar message definitions must be grouped under a named template set such as [default].',
                );
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
                'definitions' => $definitions,
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

    private function isNamedSet(string|int $key, mixed $definitions): bool
    {
        if (! is_string($key) || trim($key) === '' || ! is_array($definitions)) {
            return false;
        }

        if (array_is_list($definitions)) {
            return false;
        }

        foreach (self::DEFINITION_FIELDS as $definitionField) {
            if (array_key_exists($definitionField, $definitions)) {
                return false;
            }
        }

        return true;
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