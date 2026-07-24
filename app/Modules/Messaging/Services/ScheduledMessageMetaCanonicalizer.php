<?php

namespace App\Modules\Messaging\Services;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Stringable;

class ScheduledMessageMetaCanonicalizer
{
    public const MAX_DEPTH = 6;

    public const MAX_LIST_ITEMS = 50;

    public const MAX_MAP_ITEMS = 50;

    public const MAX_STRING_BYTES = 4096;

    public const MAX_ENCODED_BYTES = 16384;

    private const INTEGER_KEYS = [
        'campaign_enrollment_id',
        'campaign_id',
        'campaign_step_id',
        'campaign_step',
        'campaign_step_variant_id',
        'previous_scheduled_message_id',
        'webinar_registration_id',
        'webinar_waitlist_signup_id',
        'webinar_id',
        'webinar_series_id',
        'broadcast_id',
        'broadcast_recipient_id',
        'contact_import_batch_id',
        'automation_event_id',
        'task_count',
    ];

    private const STRING_KEYS = [
        'source',
        'surface',
        'notification_type',
        'campaign_key',
        'campaign_step_variant_key',
        'campaign_step_variant_source_version',
        'campaign_variant_strategy',
        'webinar_slug',
    ];

    private const BOOLEAN_KEYS = [
        'skip_when_join_clicked',
        'campaign_step_waits_for_all_scheduled_variants',
    ];

    /**
     * Canonicalize metadata for a new runtime persistence boundary.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function forPersistence(array $meta): array
    {
        return $this->canonicalizeInput($meta, true);
    }

    /**
     * Canonicalize already-current metadata. Runtime code never promotes
     * routing or scheduling aliases from discarded legacy containers.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function canonicalize(array $meta): array
    {
        return $this->canonicalizeInput($meta, false);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function canonicalizeInput(
        array $meta,
        bool $acceptWriterAliases,
    ): array
    {
        $canonical = [];

        foreach (self::INTEGER_KEYS as $key) {
            $this->copyInteger($canonical, $key, $meta[$key] ?? null);
        }

        foreach (self::STRING_KEYS as $key) {
            $this->copyString($canonical, $key, $meta[$key] ?? null);
        }

        foreach (self::BOOLEAN_KEYS as $key) {
            if (($meta[$key] ?? false) === true) {
                $canonical[$key] = true;
            }
        }

        $conditions = $this->conditions($meta['conditions'] ?? null);

        if ($conditions !== []) {
            $canonical['conditions'] = $conditions;
        }

        $consentPolicy = $this->consentPolicy($meta['consent_policy'] ?? null);

        if ($consentPolicy !== []) {
            $canonical['consent_policy'] = $consentPolicy;
        }

        $permissionInvitation = $this->permissionInvitation(
            $meta['permission_invitation'] ?? null,
        );

        if ($permissionInvitation !== []) {
            $canonical['permission_invitation'] = $permissionInvitation;
        }

        $consent = $this->consent($meta['consent'] ?? null);

        if ($consent !== []) {
            $canonical['consent'] = $consent;
        }

        $deliveryConsolidation = $this->deliveryConsolidation(
            $meta['delivery_consolidation'] ?? null,
        );

        if ($deliveryConsolidation !== []) {
            $canonical['delivery_consolidation'] = $deliveryConsolidation;
        }

        $messageTemplate = $this->messageTemplate(
            $meta,
            $acceptWriterAliases,
        );

        if ($messageTemplate !== []) {
            $canonical['message_template'] = $messageTemplate;
        }

        $webinarSchedule = $this->webinarSchedule(
            $meta,
            $acceptWriterAliases,
        );

        if ($webinarSchedule !== []) {
            $canonical['webinar_schedule'] = $webinarSchedule;
        }

        $postEvent = $this->postEvent($meta['post_event'] ?? null);

        if ($postEvent !== []) {
            $canonical['post_event'] = $postEvent;
        }

        $flowRoute = $this->identifierMap(
            $meta['flow_route'] ?? null,
            [
                'flow_route_progress_id',
                'flow_route_plan_id',
                'flow_route_plan_item_id',
                'flow_route_progress_item_id',
                'flow_route_id',
                'flow_route_point_id',
                'flow_route_capability_id',
                'automation_event_id',
            ],
        );

        if ($flowRoute !== []) {
            $canonical['flow_route'] = $flowRoute;
        }

        $automation = $this->automation($meta['automation'] ?? null);

        if ($automation !== []) {
            $canonical['automation'] = $automation;
        }

        $devTesting = $this->devTesting($meta['dev_testing'] ?? null);

        if ($devTesting !== []) {
            $canonical['dev_testing'] = $devTesting;
        }

        $taskIds = $this->integerList($meta['task_ids'] ?? null);

        if ($taskIds !== []) {
            $canonical['task_ids'] = $taskIds;
        }

        $canonical = $this->sanitizeArray($canonical, 0);
        $this->assertEncodedSize($canonical);

        return $canonical;
    }

    /**
     * Pure legacy-to-canonical metadata transformation for versioned imports.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function canonicalizeImportedMeta(array $meta): array
    {
        return $this->canonicalizeInput($meta, true);
    }

    /**
     * Pure legacy ScheduledMessage record transformation for versioned imports.
     * Normal runtime code must not call this method.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function canonicalizeImportedRecord(array $record): array
    {
        $meta = is_array($record['meta'] ?? null)
            ? $record['meta']
            : (is_array($record['metadata'] ?? null) ? $record['metadata'] : []);

        $resolvedDispatch = is_array($meta['resolved_message_dispatch'] ?? null)
            ? $meta['resolved_message_dispatch']
            : [];
        $messageScheduling = is_array($meta['message_scheduling'] ?? null)
            ? $meta['message_scheduling']
            : [];

        $this->promoteImportedValue(
            record: $record,
            key: 'queue',
            value: $meta['queue'] ?? null,
        );
        $this->promoteImportedValue(
            record: $record,
            key: 'dispatch_keys',
            value: $this->stringList($meta['dispatch_keys'] ?? null),
        );
        $this->promoteImportedValue(
            record: $record,
            key: 'definition_config_path',
            value: $meta['definition_config_path'] ?? null,
        );
        $this->promoteImportedValue(
            record: $record,
            key: 'behavior_owner_type',
            value: $resolvedDispatch['behavior_owner_type'] ?? null,
        );
        $this->promoteImportedValue(
            record: $record,
            key: 'behavior_owner_id',
            value: $this->integerValue(
                $resolvedDispatch['behavior_owner_id'] ?? null,
            ),
        );
        $this->promoteImportedValue(
            record: $record,
            key: 'send_at',
            value: $resolvedDispatch['resolved_send_at']
                ?? $messageScheduling['utc_send_at']
                ?? null,
        );

        $record['meta'] = $this->canonicalizeImportedMeta($meta);
        unset($record['metadata']);

        return $record;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $conditions = [];

        foreach (array_slice(array_values($value), 0, self::MAX_LIST_ITEMS) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $normalized = [];
            $this->copyString($normalized, 'field', $condition['field'] ?? null);
            $this->copyString($normalized, 'operator', $condition['operator'] ?? null);

            if (array_key_exists('value', $condition)) {
                $normalized['value'] = $this->sanitizeValue(
                    $condition['value'],
                    2,
                );
            }

            if (isset($normalized['field'], $normalized['operator'])) {
                $conditions[] = $normalized;
            }
        }

        return $conditions;
    }

    /**
     * @return array<string, mixed>
     */
    private function consentPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $permissionInvitation = $this->permissionInvitation(
            $value['permission_invitation'] ?? null,
        );

        return $permissionInvitation !== []
            ? ['permission_invitation' => $permissionInvitation]
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionInvitation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];
        $this->copyString($canonical, 'source', $value['source'] ?? null);
        $this->copyInteger(
            $canonical,
            'contact_import_batch_id',
            $value['contact_import_batch_id'] ?? null,
        );

        if (($value['one_time'] ?? false) === true) {
            $canonical['one_time'] = true;
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function consent(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];
        $this->copyInteger(
            $canonical,
            'message_consent_id',
            $value['message_consent_id'] ?? null,
        );
        $this->copyString(
            $canonical,
            'requested_scope',
            $value['requested_scope'] ?? null,
        );
        $this->copyString($canonical, 'domain', $value['domain'] ?? null);

        if (($value['became_active'] ?? false) === true) {
            $canonical['became_active'] = true;
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryConsolidation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ([
            'policy',
            'group',
            'primary_intent_key',
            'template_key',
            'template_source',
            'payload_key',
            'position',
        ] as $key) {
            $this->copyString($canonical, $key, $value[$key] ?? null);
        }

        $separator = $value['separator'] ?? null;

        if ($separator instanceof Stringable) {
            $separator = (string) $separator;
        }

        if (is_string($separator) && $separator !== '') {
            $this->assertStringSize($separator);
            $canonical['separator'] = $separator;
        }

        foreach (['intent_keys', 'fragment_tokens'] as $key) {
            $values = $this->stringList($value[$key] ?? null);

            if ($values !== []) {
                $canonical[$key] = $values;
            }
        }

        $consentIds = $this->integerList($value['consent_ids'] ?? null);

        if ($consentIds !== []) {
            $canonical['consent_ids'] = $consentIds;
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function messageTemplate(
        array $meta,
        bool $acceptWriterAliases,
    ): array
    {
        $existing = is_array($meta['message_template'] ?? null)
            ? $meta['message_template']
            : [];
        $preset = $acceptWriterAliases
            && is_array($meta['message_template_preset'] ?? null)
            ? $meta['message_template_preset']
            : [];
        $assignment = $acceptWriterAliases
            && is_array($meta['message_template_assignment'] ?? null)
            ? $meta['message_template_assignment']
            : [];
        $canonical = [];

        $this->copyInteger(
            $canonical,
            'preset_id',
            $existing['preset_id'] ?? $preset['id'] ?? null,
        );
        $this->copyString(
            $canonical,
            'preset_key',
            $existing['preset_key'] ?? $preset['key'] ?? null,
        );
        $this->copyInteger(
            $canonical,
            'assignment_id',
            $existing['assignment_id']
                ?? $assignment['id']
                ?? $preset['assignment_id']
                ?? null,
        );
        $this->copyString(
            $canonical,
            'definition_key',
            $existing['definition_key']
                ?? $assignment['definition_key']
                ?? null,
        );
        $this->copyString(
            $canonical,
            'campaign_step_variant_key',
            $existing['campaign_step_variant_key']
                ?? $assignment['campaign_step_variant_key']
                ?? null,
        );

        return $canonical;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function webinarSchedule(
        array $meta,
        bool $acceptWriterAliases,
    ): array
    {
        $existing = is_array($meta['webinar_schedule'] ?? null)
            ? $meta['webinar_schedule']
            : [];
        $profile = $acceptWriterAliases
            && is_array($meta['webinar_schedule_profile'] ?? null)
            ? $meta['webinar_schedule_profile']
            : [];
        $messageArea = $acceptWriterAliases
            ? ($meta['webinar_message_area'] ?? null)
            : null;
        $canonical = [];

        $this->copyInteger(
            $canonical,
            'profile_id',
            $existing['profile_id'] ?? $profile['id'] ?? null,
        );
        $this->copyString(
            $canonical,
            'profile_key',
            $existing['profile_key'] ?? $profile['key'] ?? null,
        );
        $this->copyInteger(
            $canonical,
            'item_id',
            $existing['item_id'] ?? $profile['item_id'] ?? null,
        );
        $this->copyString(
            $canonical,
            'item_key',
            $existing['item_key'] ?? $profile['item_key'] ?? null,
        );
        $this->copyString(
            $canonical,
            'area_key',
            $existing['area_key']
                ?? (is_array($messageArea) ? ($messageArea['key'] ?? null) : $messageArea),
        );

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function postEvent(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];
        $this->copyString($canonical, 'type', $value['type'] ?? null);

        if (is_bool($value['attended'] ?? null)) {
            $canonical['attended'] = $value['attended'];
        }

        return $canonical;
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function identifierMap(mixed $value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ($keys as $key) {
            $this->copyInteger($canonical, $key, $value[$key] ?? null);
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function automation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ([
            'source',
            'surface',
            'event_key',
            'execution_key',
            'correlation_key',
            'correlation_type',
        ] as $key) {
            $this->copyString($canonical, $key, $value[$key] ?? null);
        }

        foreach (['automation_event_id', 'behavior_occurrence_id'] as $key) {
            $this->copyInteger($canonical, $key, $value[$key] ?? null);
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function devTesting(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];
        $this->copyString($canonical, 'source', $value['source'] ?? null);

        if (($value['forced_immediate'] ?? false) === true) {
            $canonical['forced_immediate'] = true;
        }

        return $canonical;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringValue($item),
            $value,
        ))), 0, self::MAX_LIST_ITEMS)));
    }

    /**
     * @return array<int, int>
     */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_values(array_filter(array_map(
            fn (mixed $item): ?int => $this->integerValue($item),
            $value,
        ), fn (?int $item): bool => $item !== null)), 0, self::MAX_LIST_ITEMS)));
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyString(array &$target, string $key, mixed $value): void
    {
        $value = $this->stringValue($value);

        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyInteger(array &$target, string $key, mixed $value): void
    {
        $value = $this->integerValue($value);

        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $this->assertStringSize($value);

        return $value;
    }

    private function integerValue(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function promoteImportedValue(
        array &$record,
        string $key,
        mixed $value,
    ): void {
        if (
            array_key_exists($key, $record)
            && $record[$key] !== null
            && $record[$key] !== ''
            && $record[$key] !== []
        ) {
            return;
        }

        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $record[$key] = $value;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private function sanitizeArray(array $value, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata exceeds the maximum nesting depth.',
            );
        }

        $limit = array_is_list($value)
            ? self::MAX_LIST_ITEMS
            : self::MAX_MAP_ITEMS;

        if (count($value) > $limit) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata contains too many collection items.',
            );
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException(
                    'ScheduledMessage metadata contains an invalid key.',
                );
            }

            if (is_string($key)) {
                $this->assertStringSize($key);
            }

            $sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata exceeds the maximum nesting depth.',
            );
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $this->assertStringSize($value);

            return $value;
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value, $depth);
        }

        throw new InvalidArgumentException(
            'ScheduledMessage metadata may contain only bounded scalar and array values.',
        );
    }

    private function assertStringSize(string $value): void
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata contains an oversized string.',
            );
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function assertEncodedSize(array $meta): void
    {
        try {
            $encoded = json_encode(
                $meta,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata cannot be encoded as JSON.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException(
                'ScheduledMessage metadata exceeds the maximum encoded size.',
            );
        }
    }
}