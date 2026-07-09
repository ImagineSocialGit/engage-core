<?php

namespace App\Support\SetupValidation\Contributors;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

class ReferenceRegistrySetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'reference.keys';
    private const MODULE = 'app';

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
            runtimeKeys: Point::TYPES,
        );

        yield from $this->warnStaleRegistryKeys(
            category: 'campaign_keys',
            runtimeKeys: $this->configuredKeys('presets.campaigns.definitions'),
        );

        yield from $this->warnStaleRegistryKeys(
            category: 'flow_route_keys',
            runtimeKeys: $this->configuredKeys('presets.flow-routes.definitions'),
        );

        yield from $this->warnStaleRegistryKeys(
            category: 'task_template_keys',
            runtimeKeys: $this->configuredKeys('presets.tasks.definitions'),
        );

        yield from $this->warnUndocumentedRuntimeKeys(
            category: 'point_types',
            runtimeKeys: Point::TYPES,
        );

        yield from $this->warnUndocumentedRuntimeKeys(
            category: 'campaign_keys',
            runtimeKeys: $this->configuredKeys('presets.campaigns.definitions'),
        );

        yield from $this->warnUndocumentedRuntimeKeys(
            category: 'flow_route_keys',
            runtimeKeys: $this->configuredKeys('presets.flow-routes.definitions'),
        );

        yield from $this->warnUndocumentedRuntimeKeys(
            category: 'task_template_keys',
            runtimeKeys: $this->configuredKeys('presets.tasks.definitions'),
        );
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
     * @return array<int, string>
     */
    private function configuredKeys(string $path): array
    {
        $values = config($path, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_keys($values)),
            fn (string $key): bool => trim($key) !== '',
        ));
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
