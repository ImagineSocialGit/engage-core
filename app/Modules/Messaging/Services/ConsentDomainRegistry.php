<?php

namespace App\Modules\Messaging\Services;

use InvalidArgumentException;

class ConsentDomainRegistry
{
    /**
     * @return array<string, array{
     *     key: string,
     *     owner: string,
     *     topic: string,
     *     scopes: array<int, string>,
     *     scope_prefixes: array<int, string>,
     *     opt_in: array<string, mixed>
     * }>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->moduleKeys() as $moduleKey) {
            $configured = config("{$moduleKey}.consent_domains", []);

            if (! is_array($configured)) {
                continue;
            }

            foreach ($configured as $domainKey => $definition) {
                if (! is_string($domainKey) || trim($domainKey) === '' || ! is_array($definition)) {
                    continue;
                }

                $domainKey = $this->normalizeSegment($domainKey);

                if (isset($definitions[$domainKey])) {
                    throw new InvalidArgumentException(
                        "Consent domain [{$domainKey}] is declared by more than one module."
                    );
                }

                $definitions[$domainKey] = [
                    'key' => $domainKey,
                    'owner' => $moduleKey,
                    'topic' => $this->filledString($definition['topic'] ?? null)
                        ? trim($definition['topic'])
                        : str_replace('_', ' ', $domainKey),
                    'scopes' => $this->normalizeSegments($definition['scopes'] ?? []),
                    'scope_prefixes' => $this->normalizePrefixes($definition['scope_prefixes'] ?? []),
                    'opt_in' => is_array($definition['opt_in'] ?? null)
                        ? $definition['opt_in']
                        : [],
                ];
            }
        }

        return $definitions;
    }

    public function domainForScope(string $scope): string
    {
        $scope = $this->normalizeSegment($scope);

        if ($scope === '') {
            throw new InvalidArgumentException('Message scope must be a non-empty string.');
        }

        $exactMatches = [];

        foreach ($this->definitions() as $domainKey => $definition) {
            if ($domainKey === $scope || in_array($scope, $definition['scopes'], true)) {
                $exactMatches[] = $domainKey;
            }
        }

        if (count($exactMatches) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Message scope [%s] maps to multiple consent domains: %s.',
                $scope,
                implode(', ', $exactMatches),
            ));
        }

        if ($exactMatches !== []) {
            return $exactMatches[0];
        }

        $prefixMatches = [];

        foreach ($this->definitions() as $domainKey => $definition) {
            foreach ($definition['scope_prefixes'] as $prefix) {
                if (str_starts_with($scope, $prefix)) {
                    $prefixMatches[] = [
                        'domain' => $domainKey,
                        'prefix' => $prefix,
                        'length' => strlen($prefix),
                    ];
                }
            }
        }

        if ($prefixMatches === []) {
            // Safe fallback: an undeclared message scope is its own consent domain.
            // This never broadens permission accidentally.
            return $scope;
        }

        usort($prefixMatches, fn (array $left, array $right): int => $right['length'] <=> $left['length']);

        $longestLength = $prefixMatches[0]['length'];
        $longestDomains = array_values(array_unique(array_map(
            fn (array $match): string => $match['domain'],
            array_filter(
                $prefixMatches,
                fn (array $match): bool => $match['length'] === $longestLength,
            ),
        )));

        if (count($longestDomains) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Message scope [%s] ambiguously matches consent domains: %s.',
                $scope,
                implode(', ', $longestDomains),
            ));
        }

        return $longestDomains[0];
    }

    /**
     * @return array{
     *     key: string,
     *     owner: string,
     *     topic: string,
     *     scopes: array<int, string>,
     *     scope_prefixes: array<int, string>,
     *     opt_in: array<string, mixed>
     * }|null
     */
    public function definition(string $domain): ?array
    {
        return $this->definitions()[$this->normalizeSegment($domain)] ?? null;
    }

    public function topicForDomain(string $domain): string
    {
        $domain = $this->normalizeSegment($domain);

        return $this->definition($domain)['topic'] ?? str_replace('_', ' ', $domain);
    }

    /**
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    public function validationIssues(): array
    {
        $issues = [];
        $seenDomains = [];
        $exactOwners = [];
        $prefixOwners = [];

        foreach ($this->moduleKeys() as $moduleKey) {
            $configured = config("{$moduleKey}.consent_domains", []);

            if ($configured === null || $configured === []) {
                continue;
            }

            if (! is_array($configured)) {
                $issues[] = $this->issue(
                    'messaging.consent_domains.invalid',
                    "Consent domains for module [{$moduleKey}] must be an array.",
                    "{$moduleKey}.consent_domains",
                    ['module_key' => $moduleKey],
                );

                continue;
            }

            foreach ($configured as $domainKey => $definition) {
                $path = "{$moduleKey}.consent_domains.{$domainKey}";

                if (! is_string($domainKey) || trim($domainKey) === '' || ! is_array($definition)) {
                    $issues[] = $this->issue(
                        'messaging.consent_domain.invalid',
                        "Consent domain definition [{$path}] is invalid.",
                        $path,
                        ['module_key' => $moduleKey],
                    );

                    continue;
                }

                $normalizedDomain = $this->normalizeSegment($domainKey);

                if (isset($seenDomains[$normalizedDomain])) {
                    $issues[] = $this->issue(
                        'messaging.consent_domain.duplicate',
                        "Consent domain [{$normalizedDomain}] is declared by multiple modules.",
                        $path,
                        [
                            'domain' => $normalizedDomain,
                            'module_key' => $moduleKey,
                            'other_module_key' => $seenDomains[$normalizedDomain],
                        ],
                    );
                } else {
                    $seenDomains[$normalizedDomain] = $moduleKey;
                }

                if (! $this->filledString($definition['topic'] ?? null)) {
                    $issues[] = $this->issue(
                        'messaging.consent_domain.topic_missing',
                        "Consent domain [{$normalizedDomain}] must define a human-readable topic.",
                        "{$path}.topic",
                        ['domain' => $normalizedDomain, 'module_key' => $moduleKey],
                    );
                }

                foreach (['scopes', 'scope_prefixes'] as $field) {
                    $values = $definition[$field] ?? [];

                    if (! is_array($values)) {
                        $issues[] = $this->issue(
                            'messaging.consent_domain.mapping_invalid',
                            "Consent domain [{$normalizedDomain}] field [{$field}] must be an array.",
                            "{$path}.{$field}",
                            ['domain' => $normalizedDomain, 'module_key' => $moduleKey, 'field' => $field],
                        );

                        continue;
                    }

                    foreach ($values as $index => $value) {
                        if (! $this->filledString($value)) {
                            $issues[] = $this->issue(
                                'messaging.consent_domain.mapping_invalid',
                                "Consent domain [{$normalizedDomain}] contains an invalid [{$field}] value.",
                                "{$path}.{$field}.{$index}",
                                ['domain' => $normalizedDomain, 'module_key' => $moduleKey, 'field' => $field],
                            );

                            continue;
                        }

                        $normalizedValue = $field === 'scope_prefixes'
                            ? $this->normalizePrefix($value)
                            : $this->normalizeSegment($value);

                        $owners = $field === 'scope_prefixes' ? $prefixOwners : $exactOwners;

                        if (isset($owners[$normalizedValue]) && $owners[$normalizedValue] !== $normalizedDomain) {
                            $issues[] = $this->issue(
                                'messaging.consent_domain.mapping_duplicate',
                                "Consent mapping [{$normalizedValue}] belongs to more than one consent domain.",
                                "{$path}.{$field}.{$index}",
                                [
                                    'domain' => $normalizedDomain,
                                    'other_domain' => $owners[$normalizedValue],
                                    'mapping' => $normalizedValue,
                                    'field' => $field,
                                ],
                            );
                        }

                        if ($field === 'scope_prefixes') {
                            $prefixOwners[$normalizedValue] = $normalizedDomain;
                        } else {
                            $exactOwners[$normalizedValue] = $normalizedDomain;
                        }
                    }
                }
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function moduleKeys(): array
    {
        $modules = config('modules.modules', []);

        if (! is_array($modules)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($modules),
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));
    }

    /** @return array<int, string> */
    private function normalizeSegments(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => $this->filledString($value)
                ? $this->normalizeSegment($value)
                : '',
            $values,
        ))));
    }

    /** @return array<int, string> */
    private function normalizePrefixes(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => $this->filledString($value)
                ? $this->normalizePrefix($value)
                : '',
            $values,
        ))));
    }

    private function normalizePrefix(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param array<string, mixed> $context
     * @return array{code: string, message: string, path: string, context: array<string, mixed>}
     */
    private function issue(string $code, string $message, string $path, array $context): array
    {
        return compact('code', 'message', 'path', 'context');
    }
}
