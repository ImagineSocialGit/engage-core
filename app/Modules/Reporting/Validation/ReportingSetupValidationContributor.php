<?php

namespace App\Modules\Reporting\Validation;

use App\Modules\Reporting\Services\ReportingBrowserRequestClassifier;
use App\Support\Reporting\Data\ReportingEventDefinition;
use App\Support\Reporting\ReportingEventDefinitionRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Throwable;

final class ReportingSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'reporting';
    private const MODULE = 'reporting';

    private const UTM_DIMENSIONS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
    ];

    private const EXTERNAL_ATTRIBUTION_KEYS = [
        'platform' => 'engage_platform',
        'campaign_id' => 'engage_campaign_id',
        'group_id' => 'engage_group_id',
        'creative_id' => 'engage_creative_id',
        'placement' => 'engage_placement',
    ];

    public function __construct(
        private readonly ReportingEventDefinitionRegistry $definitions,
    ) {}

    public function findings(): iterable
    {
        yield from $this->validateSessionConfig();
        yield from $this->validateCollectionConfig();
        yield from $this->validateIngestionConfig();
        yield from $this->validateClassificationConfig();
        yield from $this->validateAttributionConfig();
        yield from $this->validateRetentionConfig();
        yield from $this->validateEventDefinitions();
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateSessionConfig(): iterable
    {
        $inactivity = config('reporting.session.inactivity_minutes');
        $absolute = config('reporting.session.absolute_minutes');
        $minToken = config('reporting.session.token_min_length');
        $maxToken = config('reporting.session.token_max_length');

        if (! $this->integerBetween($inactivity, 1, 30)) {
            yield $this->error(
                'reporting.session.inactivity_invalid',
                'Reporting session inactivity must be an integer between 1 and 30 minutes.',
                'reporting.session.inactivity_minutes',
            );
        }

        if (! $this->integerBetween($absolute, 1, 240)) {
            yield $this->error(
                'reporting.session.absolute_invalid',
                'Reporting absolute session lifetime must be an integer between 1 and 240 minutes.',
                'reporting.session.absolute_minutes',
            );
        }

        if (is_int($inactivity)
            && is_int($absolute)
            && $absolute < $inactivity
        ) {
            yield $this->error(
                'reporting.session.absolute_before_inactivity',
                'Reporting absolute session lifetime cannot be shorter than the inactivity boundary.',
                'reporting.session.absolute_minutes',
            );
        }

        if (! $this->integerBetween($minToken, 16, 255)) {
            yield $this->error(
                'reporting.session.token_min_invalid',
                'Reporting session token minimum length must be between 16 and 255 characters.',
                'reporting.session.token_min_length',
            );
        }

        if (! $this->integerBetween($maxToken, 16, 255)) {
            yield $this->error(
                'reporting.session.token_max_invalid',
                'Reporting session token maximum length must be between 16 and 255 characters.',
                'reporting.session.token_max_length',
            );
        }

        if (is_int($minToken) && is_int($maxToken) && $maxToken < $minToken) {
            yield $this->error(
                'reporting.session.token_range_invalid',
                'Reporting session token maximum length cannot be smaller than the minimum length.',
                'reporting.session.token_max_length',
            );
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateCollectionConfig(): iterable
    {
        $browserEnabled = config('reporting.collection.browser_enabled');

        if (! is_bool($browserEnabled)) {
            yield $this->error(
                'reporting.collection.browser_enabled_invalid',
                'Reporting collection browser_enabled must be boolean.',
                'reporting.collection.browser_enabled',
                context: [
                    'configured_type' => get_debug_type($browserEnabled),
                ],
            );
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateIngestionConfig(): iterable
    {
        foreach ([
            'max_payload_bytes' => [256, 8192],
            'max_properties' => [1, 16],
            'max_property_key_length' => [1, 80],
            'max_string_length' => [1, 512],
            'max_string_list_items' => [1, 16],
            'max_classification_reasons' => [1, 8],
            'occurred_at_past_seconds' => [0, 86400],
            'occurred_at_future_seconds' => [0, 300],
            'rate_limit_per_ip_per_minute' => [1, 120],
            'rate_limit_per_session_per_minute' => [1, 90],
        ] as $key => [$minimum, $maximum]) {
            $value = config("reporting.ingestion.{$key}");

            if (! $this->integerBetween($value, $minimum, $maximum)) {
                yield $this->error(
                    'reporting.ingestion.'.$key.'_invalid',
                    "Reporting ingestion [{$key}] must be an integer between {$minimum} and {$maximum}.",
                    "reporting.ingestion.{$key}",
                );
            }
        }

        $sources = config('reporting.ingestion.allowed_sources');

        if (! is_array($sources) || $sources === [] || ! array_is_list($sources)) {
            yield $this->error(
                'reporting.ingestion.allowed_sources_invalid',
                'Reporting allowed_sources must be a non-empty list.',
                'reporting.ingestion.allowed_sources',
            );

            return;
        }

        foreach ($sources as $index => $source) {
            if (! is_string($source)
                || trim($source) === ''
                || strlen(trim($source)) > 32
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', trim($source)) !== 1
            ) {
                yield $this->error(
                    'reporting.ingestion.allowed_source_invalid',
                    'Reporting allowed_sources entries must be lowercase bounded identifiers.',
                    "reporting.ingestion.allowed_sources.{$index}",
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateClassificationConfig(): iterable
    {
        $classifier = config('reporting.classification.browser_classifier');

        if ($classifier !== ReportingBrowserRequestClassifier::CONFIG_KEY) {
            yield $this->error(
                'reporting.classification.browser_classifier_invalid',
                'Reporting browser classifier must resolve to the supported '.ReportingBrowserRequestClassifier::CONFIG_KEY.' implementation.',
                'reporting.classification.browser_classifier',
            );
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateAttributionConfig(): iterable
    {
        foreach ([
            'path_max_length' => [1, 512],
            'host_max_length' => [1, 255],
            'value_max_length' => [1, 120],
        ] as $key => [$minimum, $maximum]) {
            $value = config("reporting.attribution.{$key}");

            if (! $this->integerBetween($value, $minimum, $maximum)) {
                yield $this->error(
                    'reporting.attribution.'.$key.'_invalid',
                    "Reporting attribution [{$key}] must be an integer between {$minimum} and {$maximum}.",
                    "reporting.attribution.{$key}",
                );
            }
        }

        $utmKeys = config('reporting.attribution.utm_keys');

        if (! is_array($utmKeys)) {
            yield $this->error(
                'reporting.attribution.utm_keys_invalid',
                'Reporting attribution utm_keys must be an associative array.',
                'reporting.attribution.utm_keys',
            );
        } else {
            foreach ($utmKeys as $dimension => $queryKey) {
                if (! in_array($dimension, self::UTM_DIMENSIONS, true)
                    || $queryKey !== $dimension
                ) {
                    yield $this->error(
                        'reporting.attribution.utm_key_not_allowlisted',
                        'Reporting may only collect the conventional allowlisted UTM dimensions using their canonical query keys.',
                        "reporting.attribution.utm_keys.{$dimension}",
                    );
                }
            }
        }

        $externalKeys = config('reporting.attribution.external_keys');
        $expectedExternalKeys = self::EXTERNAL_ATTRIBUTION_KEYS;

        if (is_array($externalKeys)) {
            ksort($externalKeys);
            ksort($expectedExternalKeys);
        }

        if (! is_array($externalKeys)
            || $externalKeys !== $expectedExternalKeys
        ) {
            yield $this->error(
                'reporting.attribution.external_keys_invalid',
                'Reporting external attribution must use the canonical engage_* transport keys.',
                'reporting.attribution.external_keys',
            );
        }

        $clickIds = config('reporting.attribution.click_id_keys');

        if (! is_array($clickIds)) {
            yield $this->error(
                'reporting.attribution.click_id_keys_invalid',
                'Reporting attribution click_id_keys must be an associative array.',
                'reporting.attribution.click_id_keys',
            );

            return;
        }

        foreach ($clickIds as $queryKey => $canonicalKey) {
            if (! is_string($queryKey)
                || trim($queryKey) === ''
                || ! is_string($canonicalKey)
                || trim($canonicalKey) === ''
                || strlen($queryKey) > 80
                || strlen($canonicalKey) > 80
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $queryKey) !== 1
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $canonicalKey) !== 1
            ) {
                yield $this->error(
                    'reporting.attribution.click_id_key_invalid',
                    'Approved Reporting click identifier keys must be bounded lowercase identifiers.',
                    'reporting.attribution.click_id_keys',
                );
            }
        }

        if ($clickIds !== []) {
            $hashKey = config('reporting.attribution.click_id_hash_key');

            if (! is_string($hashKey) || strlen($hashKey) < 32) {
                yield $this->error(
                    'reporting.attribution.click_id_hash_key_missing',
                    'Approved Reporting click identifiers require a dedicated hash key of at least 32 characters.',
                    'reporting.attribution.click_id_hash_key',
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateRetentionConfig(): iterable
    {
        foreach ([
            'raw_observations_days' => [1, 45],
            'diagnostics_days' => [1, 90],
            'daily_aggregate_months' => [1, 25],
        ] as $key => [$minimum, $maximum]) {
            $value = config("reporting.retention.{$key}");

            if (! $this->integerBetween($value, $minimum, $maximum)) {
                yield $this->error(
                    'reporting.retention.'.$key.'_invalid',
                    "Reporting retention [{$key}] must be an integer between {$minimum} and {$maximum}.",
                    "reporting.retention.{$key}",
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateEventDefinitions(): iterable
    {
        try {
            $definitions = $this->definitions->definitions();
        } catch (Throwable $exception) {
            yield $this->error(
                'reporting.events.definition_invalid',
                'Reporting event definitions could not be resolved: '.$exception->getMessage(),
                'reporting.events',
                meta: ['exception' => $exception::class],
            );

            return;
        }

        $maxProperties = (int) config('reporting.ingestion.max_properties', 16);
        $maxPropertyKeyLength = (int) config('reporting.ingestion.max_property_key_length', 80);
        $maxStringLength = (int) config('reporting.ingestion.max_string_length', 512);
        $maxStringListItems = (int) config('reporting.ingestion.max_string_list_items', 16);

        foreach ($definitions as $identity => $definition) {
            if (count($definition->properties) > $maxProperties) {
                yield $this->error(
                    'reporting.events.property_count_exceeds_limit',
                    "Reporting event [{$identity}] defines more properties than the global ingestion limit.",
                    "reporting.events.{$definition->key}.{$definition->version}.properties",
                );
            }

            foreach ($definition->properties as $propertyKey => $rules) {
                if (strlen($propertyKey) > $maxPropertyKeyLength) {
                    yield $this->error(
                        'reporting.events.property_key_exceeds_limit',
                        "Reporting event [{$identity}] property [{$propertyKey}] exceeds the configured property-key length limit.",
                        "reporting.events.{$definition->key}.{$definition->version}.properties.{$propertyKey}",
                    );
                }
                if (isset($rules['max_length']) && $rules['max_length'] > $maxStringLength) {
                    yield $this->error(
                        'reporting.events.property_max_length_exceeds_limit',
                        "Reporting event [{$identity}] property [{$propertyKey}] exceeds the global string-length limit.",
                        "reporting.events.{$definition->key}.{$definition->version}.properties.{$propertyKey}.max_length",
                    );
                }

                if (isset($rules['max_item_length']) && $rules['max_item_length'] > $maxStringLength) {
                    yield $this->error(
                        'reporting.events.property_item_length_exceeds_limit',
                        "Reporting event [{$identity}] property [{$propertyKey}] exceeds the global string-item length limit.",
                        "reporting.events.{$definition->key}.{$definition->version}.properties.{$propertyKey}.max_item_length",
                    );
                }

                if (isset($rules['max_items']) && $rules['max_items'] > $maxStringListItems) {
                    yield $this->error(
                        'reporting.events.property_item_count_exceeds_limit',
                        "Reporting event [{$identity}] property [{$propertyKey}] exceeds the global string-list item limit.",
                        "reporting.events.{$definition->key}.{$definition->version}.properties.{$propertyKey}.max_items",
                    );
                }

                if (($rules['type'] ?? null) === ReportingEventDefinition::PROPERTY_ENUM) {
                    foreach ($rules['values'] as $valueIndex => $value) {
                        if (strlen($value) > $maxStringLength) {
                            yield $this->error(
                                'reporting.events.enum_value_exceeds_limit',
                                "Reporting event [{$identity}] property [{$propertyKey}] contains an enum value that exceeds the global string-length limit.",
                                "reporting.events.{$definition->key}.{$definition->version}.properties.{$propertyKey}.values.{$valueIndex}",
                            );
                        }
                    }
                }
            }
        }
    }

    private function integerBetween(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
            meta: $meta,
        );
    }
}