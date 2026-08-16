<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Services\ReportingAttributionNormalizer;
use App\Modules\Reporting\Services\ReportingSessionResolver;
use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use App\Support\Reporting\Data\ReportingEventDefinition;
use App\Support\Reporting\Data\ReportingObservationData;
use App\Support\Reporting\Data\ReportingObservationResult;
use App\Support\Reporting\ReportingEventDefinitionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class RecordReportingObservationAction implements ReportingObservationRecorder
{
    private const TRAFFIC_CLASSES = [
        'likely_human',
        'likely_automated',
        'unknown',
    ];

    public function __construct(
        private readonly ReportingEventDefinitionRegistry $definitions,
        private readonly ReportingAttributionNormalizer $attributionNormalizer,
        private readonly ReportingSessionResolver $sessionResolver,
    ) {}

    public function record(ReportingObservationData $observation): ReportingObservationResult
    {
        $receivedAt = CarbonImmutable::instance(now('UTC'));
        $normalized = $this->normalize($observation, $receivedAt);
        $payloadHash = $this->payloadHash($normalized);

        try {
            return DB::transaction(function () use (
                $observation,
                $receivedAt,
                $normalized,
                $payloadHash,
            ): ReportingObservationResult {
                $existing = ReportingObservation::query()
                    ->where('event_id', $normalized['event_id'])
                    ->first();

                if ($existing instanceof ReportingObservation) {
                    return $this->existingResult($existing, $payloadHash);
                }

                $session = $this->sessionResolver->resolve(
                    sessionToken: $observation->sessionToken,
                    host: $normalized['host'],
                    surface: $normalized['surface'],
                    attribution: $normalized['attribution'],
                    receivedAt: $receivedAt,
                    trafficClass: $normalized['traffic_class'],
                    classifierKey: $normalized['classifier_key'],
                    classifierVersion: $normalized['classifier_version'],
                    classificationReasons: $normalized['classification_reasons'],
                    deviceClass: $normalized['device_class'],
                    browserFamily: $normalized['browser_family'],
                    osFamily: $normalized['os_family'],
                );

                $attribution = $normalized['attribution'];

                $stored = ReportingObservation::query()->create([
                    'event_id' => $normalized['event_id'],
                    'payload_hash' => $payloadHash,
                    'reporting_session_id' => $session?->getKey(),
                    'event_key' => $normalized['event_key'],
                    'event_version' => $normalized['event_version'],
                    'source' => $normalized['source'],
                    'occurred_at' => $normalized['occurred_at'],
                    'received_at' => $receivedAt,
                    'host' => $normalized['host'],
                    'surface' => $normalized['surface'],
                    'path' => $attribution->path,
                    'referrer_host' => $attribution->referrerHost,
                    'utm_source' => $attribution->utmSource,
                    'utm_medium' => $attribution->utmMedium,
                    'utm_campaign' => $attribution->utmCampaign,
                    'utm_content' => $attribution->utmContent,
                    'utm_term' => $attribution->utmTerm,
                    'click_id_hashes' => $attribution->clickIdHashes !== []
                        ? $attribution->clickIdHashes
                        : null,
                    'traffic_class' => $normalized['traffic_class'],
                    'classifier_key' => $normalized['classifier_key'],
                    'classifier_version' => $normalized['classifier_version'],
                    'classification_reasons' => $normalized['classification_reasons'] !== []
                        ? $normalized['classification_reasons']
                        : null,
                    'device_class' => $normalized['device_class'],
                    'browser_family' => $normalized['browser_family'],
                    'os_family' => $normalized['os_family'],
                    'properties' => $normalized['properties'] !== []
                        ? $normalized['properties']
                        : null,
                ]);

                return ReportingObservationResult::recorded(
                    eventId: $stored->event_id,
                    observationId: (int) $stored->getKey(),
                    sessionId: $stored->reporting_session_id,
                );
            });
        } catch (QueryException $exception) {
            $existing = ReportingObservation::query()
                ->where('event_id', $normalized['event_id'])
                ->first();

            if (! $existing instanceof ReportingObservation) {
                throw $exception;
            }

            return $this->existingResult($existing, $payloadHash);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(
        ReportingObservationData $observation,
        CarbonImmutable $receivedAt,
    ): array {
        $eventId = strtolower(trim($observation->eventId));

        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Reporting observation event ID must be a UUID.');
        }

        $eventKey = strtolower(trim($observation->eventKey));
        $definition = $this->definitions->require($eventKey, $observation->eventVersion);
        $source = $this->normalizeIdentifier($observation->source, 32, 'source');
        $surface = $this->normalizeIdentifier($observation->surface, 80, 'surface', replaceHyphen: true);
        $host = $this->attributionNormalizer->normalizeHost($observation->host);

        if (! $definition->allowsSurface($surface)) {
            throw new InvalidArgumentException(
                "Reporting event [{$eventKey}:{$observation->eventVersion}] is not allowed on surface [{$surface}].",
            );
        }

        if (! in_array($source, $this->allowedSources(), true)) {
            throw new InvalidArgumentException("Reporting observation source [{$source}] is not allowed.");
        }

        if ($definition->sessionMode === ReportingEventDefinition::SESSION_NONE
            && is_string($observation->sessionToken)
            && trim($observation->sessionToken) !== ''
        ) {
            throw new InvalidArgumentException(
                "Reporting event [{$eventKey}:{$observation->eventVersion}] does not permit session correlation.",
            );
        }

        $occurredAt = CarbonImmutable::instance($observation->occurredAt)->utc();
        $this->validateOccurredAt($occurredAt, $receivedAt);

        $attribution = $this->attributionNormalizer->normalize(
            path: $observation->path,
            referrer: $observation->referrer,
            query: $observation->query,
        );

        $properties = $this->normalizeProperties($definition, $observation->properties);
        $trafficClass = strtolower(trim($observation->trafficClass));

        if (! in_array($trafficClass, self::TRAFFIC_CLASSES, true)) {
            throw new InvalidArgumentException("Unsupported Reporting traffic class [{$trafficClass}].");
        }

        $classificationReasons = $this->normalizeStringList(
            values: $observation->classificationReasons,
            label: 'classification reason',
            maximumItems: $this->maxClassificationReasons(),
            maximumLength: 80,
        );

        $normalized = [
            'event_id' => $eventId,
            'event_key' => $eventKey,
            'event_version' => $observation->eventVersion,
            'source' => $source,
            'occurred_at' => $occurredAt,
            'host' => $host,
            'surface' => $surface,
            'attribution' => $attribution,
            'session_token_hash' => $this->sessionResolver->tokenHash($observation->sessionToken),
            'traffic_class' => $trafficClass,
            'classifier_key' => $this->nullableBoundedIdentifier($observation->classifierKey, 80),
            'classifier_version' => $this->nullablePositiveSmallInteger($observation->classifierVersion, 'classifier version'),
            'classification_reasons' => $classificationReasons,
            'device_class' => $this->nullableBoundedIdentifier($observation->deviceClass, 50),
            'browser_family' => $this->nullableBoundedLabel($observation->browserFamily, 80),
            'os_family' => $this->nullableBoundedLabel($observation->osFamily, 80),
            'properties' => $properties,
        ];

        $this->assertPayloadWithinLimit($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function normalizeProperties(
        ReportingEventDefinition $definition,
        array $properties,
    ): array {
        if (count($properties) > $this->maxProperties()) {
            throw new InvalidArgumentException('Reporting observation contains too many properties.');
        }

        $unknown = array_values(array_diff(
            array_keys($properties),
            array_keys($definition->properties),
        ));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Reporting event [%s:%d] contains unknown property key(s): %s.',
                $definition->key,
                $definition->version,
                implode(', ', $unknown),
            ));
        }

        $normalized = [];

        foreach ($definition->properties as $propertyKey => $rules) {
            $present = array_key_exists($propertyKey, $properties);

            if (! $present) {
                if ((bool) ($rules['required'] ?? false)) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$definition->key}:{$definition->version}] is missing required property [{$propertyKey}].",
                    );
                }

                continue;
            }

            $normalized[$propertyKey] = $this->normalizePropertyValue(
                propertyKey: $propertyKey,
                value: $properties[$propertyKey],
                rules: $rules,
            );
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function normalizePropertyValue(
        string $propertyKey,
        mixed $value,
        array $rules,
    ): mixed {
        $type = $rules['type'];

        return match ($type) {
            ReportingEventDefinition::PROPERTY_STRING => $this->boundedString(
                value: $value,
                label: "property [{$propertyKey}]",
                maximumLength: min(
                    (int) ($rules['max_length'] ?? $this->maxStringLength()),
                    $this->maxStringLength(),
                ),
            ),
            ReportingEventDefinition::PROPERTY_INTEGER => $this->boundedInteger(
                value: $value,
                label: "property [{$propertyKey}]",
                minimum: $rules['min'] ?? null,
                maximum: $rules['max'] ?? null,
            ),
            ReportingEventDefinition::PROPERTY_BOOLEAN => $this->strictBoolean(
                value: $value,
                label: "property [{$propertyKey}]",
            ),
            ReportingEventDefinition::PROPERTY_ENUM => $this->enumValue(
                value: $value,
                label: "property [{$propertyKey}]",
                allowed: $rules['values'],
            ),
            ReportingEventDefinition::PROPERTY_STRING_LIST => $this->normalizeStringList(
                values: $value,
                label: "property [{$propertyKey}] item",
                maximumItems: min(
                    (int) ($rules['max_items'] ?? $this->maxStringListItems()),
                    $this->maxStringListItems(),
                ),
                maximumLength: min(
                    (int) ($rules['max_item_length'] ?? $this->maxStringLength()),
                    $this->maxStringLength(),
                ),
            ),
            default => throw new InvalidArgumentException(
                "Unsupported Reporting property type [{$type}].",
            ),
        };
    }

    private function existingResult(
        ReportingObservation $existing,
        string $payloadHash,
    ): ReportingObservationResult {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw new LogicException(
                "Reporting event ID [{$existing->event_id}] was replayed with conflicting normalized content.",
            );
        }

        return ReportingObservationResult::deduplicated(
            eventId: $existing->event_id,
            observationId: (int) $existing->getKey(),
            sessionId: $existing->reporting_session_id,
        );
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function payloadHash(array $normalized): string
    {
        $hashable = $normalized;
        $hashable['occurred_at'] = $normalized['occurred_at']->format('Y-m-d\TH:i:s.uP');
        $hashable['attribution'] = $normalized['attribution']->toArray();

        try {
            $json = json_encode(
                $this->canonicalize($hashable),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Reporting observation could not be normalized for idempotency.',
                previous: $exception,
            );
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertPayloadWithinLimit(array $normalized): void
    {
        $payload = $normalized;
        $payload['occurred_at'] = $normalized['occurred_at']->format('Y-m-d\TH:i:s.uP');
        $payload['attribution'] = $normalized['attribution']->toArray();

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Reporting observation payload could not be serialized.',
                previous: $exception,
            );
        }

        if (strlen($json) > $this->maxPayloadBytes()) {
            throw new InvalidArgumentException('Reporting observation exceeds the configured payload limit.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function validateOccurredAt(
        CarbonImmutable $occurredAt,
        CarbonImmutable $receivedAt,
    ): void {
        if ($occurredAt->lessThan($receivedAt->subSeconds($this->pastSeconds()))) {
            throw new InvalidArgumentException('Reporting observation occurred_at is too far in the past.');
        }

        if ($occurredAt->greaterThan($receivedAt->addSeconds($this->futureSeconds()))) {
            throw new InvalidArgumentException('Reporting observation occurred_at is too far in the future.');
        }
    }

    private function normalizeIdentifier(
        string $value,
        int $maximumLength,
        string $label,
        bool $replaceHyphen = false,
    ): string {
        $value = strtolower(trim($value));

        if ($replaceHyphen) {
            $value = str_replace('-', '_', $value);
        }

        if ($value === ''
            || strlen($value) > $maximumLength
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) !== 1
        ) {
            throw new InvalidArgumentException("Reporting {$label} is invalid.");
        }

        return $value;
    }

    private function nullableBoundedIdentifier(?string $value, int $maximumLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->normalizeIdentifier($value, $maximumLength, 'identifier');
    }

    private function nullableBoundedLabel(?string $value, int $maximumLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->boundedString($value, 'classification label', $maximumLength);
    }

    private function nullablePositiveSmallInteger(?int $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 1 || $value > 65535) {
            throw new InvalidArgumentException("Reporting {$label} must be between 1 and 65535.");
        }

        return $value;
    }

    private function boundedString(mixed $value, string $label, int $maximumLength): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Reporting {$label} must be a string.");
        }

        $value = trim($value);

        if ($value === ''
            || strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException("Reporting {$label} is invalid or too long.");
        }

        return $value;
    }

    private function boundedInteger(
        mixed $value,
        string $label,
        ?int $minimum,
        ?int $maximum,
    ): int {
        if (! is_int($value)) {
            throw new InvalidArgumentException("Reporting {$label} must be an integer.");
        }

        if ($minimum !== null && $value < $minimum) {
            throw new InvalidArgumentException("Reporting {$label} is below its allowed minimum.");
        }

        if ($maximum !== null && $value > $maximum) {
            throw new InvalidArgumentException("Reporting {$label} exceeds its allowed maximum.");
        }

        return $value;
    }

    private function strictBoolean(mixed $value, string $label): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Reporting {$label} must be boolean.");
        }

        return $value;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function enumValue(mixed $value, string $label, array $allowed): string
    {
        $value = $this->boundedString($value, $label, $this->maxStringLength());

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Reporting {$label} has an unsupported value [{$value}].");
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(
        mixed $values,
        string $label,
        int $maximumItems,
        int $maximumLength,
    ): array {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("Reporting {$label} values must be a list.");
        }

        if (count($values) > $maximumItems) {
            throw new InvalidArgumentException("Reporting {$label} list contains too many items.");
        }

        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = $this->boundedString($value, $label, $maximumLength);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<int, string> */
    private function allowedSources(): array
    {
        $sources = config('reporting.ingestion.allowed_sources', ['browser', 'server']);

        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $source): ?string => is_string($source) && trim($source) !== ''
                ? strtolower(trim($source))
                : null,
            $sources,
        ))));
    }

    private function maxPayloadBytes(): int
    {
        return min(8192, max(256, (int) config('reporting.ingestion.max_payload_bytes', 8192)));
    }

    private function maxProperties(): int
    {
        return min(16, max(1, (int) config('reporting.ingestion.max_properties', 16)));
    }

    private function maxStringLength(): int
    {
        return min(512, max(1, (int) config('reporting.ingestion.max_string_length', 512)));
    }

    private function maxStringListItems(): int
    {
        return min(16, max(1, (int) config('reporting.ingestion.max_string_list_items', 16)));
    }

    private function maxClassificationReasons(): int
    {
        return min(8, max(1, (int) config('reporting.ingestion.max_classification_reasons', 8)));
    }

    private function pastSeconds(): int
    {
        return min(86400, max(0, (int) config('reporting.ingestion.occurred_at_past_seconds', 86400)));
    }

    private function futureSeconds(): int
    {
        return min(300, max(0, (int) config('reporting.ingestion.occurred_at_future_seconds', 300)));
    }
}