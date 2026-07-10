<?php

namespace App\Support\SetupValidation\Contributors;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

class ReferenceRegistrySetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'reference.keys';
    private const MODULE = 'app';

    public function __construct(
        private readonly PresetContributionRegistry $presetContributionRegistry,
    ) {}

    public function findings(): iterable
    {
        $registry = config('reference.keys', []);

        if (! is_array($registry)) {
            yield $this->error(
                code: 'app.reference.registry_invalid',
                message: 'reference.keys must be an array.',
                path: 'reference.keys',
            );

            return;
        }

        yield from $this->warnStaleRegistryKeys(
            category: 'channels',
            runtimeKeys: MessageChannel::values(),
        );

        yield from $this->warnStaleRegistryKeys(
            category: 'purposes',
            runtimeKeys: MessagePurpose::values(),
        );

        yield from $this->warnStaleRegistryKeys(
            category: 'point_types',
            runtimeKeys: FlowRoutePointType::values(),
        );

        foreach ($this->presetReferenceDomains() as $category => $domain) {
            yield from $this->warnStaleRegistryKeys(
                category: $category,
                runtimeKeys: $this->presetDefinitionKeys($domain),
            );
        }

        yield from $this->warnUndocumentedRuntimeKeys(
            category: 'point_types',
            runtimeKeys: FlowRoutePointType::values(),
        );

        foreach ($this->presetReferenceDomains() as $category => $domain) {
            yield from $this->warnUndocumentedRuntimeKeys(
                category: $category,
                runtimeKeys: $this->presetDefinitionKeys($domain),
            );
        }
    }

    /**
     * @param array<int, string> $runtimeKeys
     * @return iterable<int, SetupValidationFinding>
     */
    private function warnStaleRegistryKeys(string $category, array $runtimeKeys): iterable
    {
        foreach ($this->activeRegistryKeys($category) as $key) {
            if (in_array($key, $runtimeKeys, true)) {
                continue;
            }

            yield $this->warning(
                code: 'app.reference.stale_unused_key',
                message: "Reference registry category [{$category}] documents key [{$key}] with no current owning runtime/config definition.",
                path: "reference.keys.{$category}.{$key}",
                context: [
                    'category' => $category,
                    'key' => $key,
                ],
            );
        }
    }

    /**
     * @param array<int, string> $runtimeKeys
     * @return iterable<int, SetupValidationFinding>
     */
    private function warnUndocumentedRuntimeKeys(string $category, array $runtimeKeys): iterable
    {
        $documented = $this->registryKeys($category);

        foreach ($runtimeKeys as $key) {
            if (in_array($key, $documented, true)) {
                continue;
            }

            yield $this->warning(
                code: 'app.reference.runtime_key_undocumented',
                message: "Owning runtime/config source defines [{$key}] but reference registry category [{$category}] does not document it.",
                path: "reference.keys.{$category}",
                context: [
                    'category' => $category,
                    'key' => $key,
                ],
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function registryKeys(string $category): array
    {
        $values = config("reference.keys.{$category}", []);

        if (! is_array($values)) {
            return [];
        }

        if (array_is_list($values)) {
            return array_values(array_unique(array_filter(
                array_map(
                    fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                        ? trim($value)
                        : null,
                    $values,
                ),
            )));
        }

        return array_values(array_filter(
            array_map('strval', array_keys($values)),
            fn (string $key): bool => trim($key) !== '',
        ));
    }

    /**
     * @return array<int, string>
     */
    private function activeRegistryKeys(string $category): array
    {
        $values = config("reference.keys.{$category}", []);

        if (! is_array($values)) {
            return [];
        }

        if (array_is_list($values)) {
            return $this->registryKeys($category);
        }

        $keys = [];

        foreach ($values as $key => $definition) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            if (is_array($definition)) {
                $status = $definition['status'] ?? null;

                if (is_string($status) && in_array(strtolower(trim($status)), [
                    'future',
                    'planned',
                    'deprecated',
                    'avoid',
                ], true)) {
                    continue;
                }
            }

            $keys[] = trim($key);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, PresetDomain>
     */
    private function presetReferenceDomains(): array
    {
        $domains = [];

        foreach (PresetDomain::cases() as $domain) {
            $category = $domain->referenceRegistryCategory();

            if ($category === null) {
                continue;
            }

            $domains[$category] = $domain;
        }

        return $domains;
    }

    /**
     * @return array<int, string>
     */
    private function presetDefinitionKeys(PresetDomain $domain): array
    {
        return array_keys(
            $this->presetContributionRegistry->definitions($domain),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}