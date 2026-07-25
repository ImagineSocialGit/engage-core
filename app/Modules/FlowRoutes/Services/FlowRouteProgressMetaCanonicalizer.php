<?php

namespace App\Modules\FlowRoutes\Services;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;

class FlowRouteProgressMetaCanonicalizer
{
    public const MAX_CORRELATION_ITEMS = 25;

    public const MAX_CORRELATION_LIST_ITEMS = 25;

    public const MAX_STRING_BYTES = 1024;

    public const MAX_ENCODED_BYTES = 4096;

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function forPersistence(array $meta): array
    {
        $canonical = [];

        $workflowTransition = $this->workflowTransition(
            $meta['started_from_workflow_transition'] ?? null,
        );

        if ($workflowTransition !== []) {
            $canonical['started_from_workflow_transition'] = $workflowTransition;
        }

        $automationEvent = $this->automationEventReference(
            $meta['started_from_automation_event'] ?? null,
        );

        if ($automationEvent !== []) {
            $canonical['started_from_automation_event'] = $automationEvent;
        }

        $waiting = $this->waiting(
            $meta['waiting'] ?? null,
        );

        if ($waiting !== []) {
            $canonical['waiting'] = $waiting;
        }

        $continuation = $this->continuation(
            $meta['immediate_execution_continuation'] ?? null,
        );

        if ($continuation !== []) {
            $canonical['immediate_execution_continuation'] = $continuation;
        }

        $this->assertEncodedSize($canonical);

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowTransition(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ([
            'from_contact_status_id',
            'to_contact_status_id',
            'actor_id',
        ] as $key) {
            $this->copyInteger($canonical, $key, $value[$key] ?? null);
        }

        foreach ([
            'reason',
            'source',
            'actor_type',
        ] as $key) {
            $this->copyString($canonical, $key, $value[$key] ?? null);
        }

        $this->copyTimestamp(
            $canonical,
            'changed_at',
            $value['changed_at'] ?? null,
        );

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function automationEventReference(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ([
            'name',
            'event_id',
            'subject_type',
        ] as $key) {
            $this->copyString($canonical, $key, $value[$key] ?? null);
        }

        $this->copyInteger(
            $canonical,
            'contact_id',
            $value['contact_id'] ?? null,
        );
        $this->copySubjectId(
            $canonical,
            $value['subject_id'] ?? null,
        );
        $this->copyTimestamp(
            $canonical,
            'occurred_at',
            $value['occurred_at'] ?? null,
        );

        return isset($canonical['name'])
            ? $canonical
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function waiting(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $canonical = [];

        foreach ([
            'flow_route_plan_id',
            'flow_route_plan_item_id',
            'flow_route_progress_item_id',
            'flow_route_point_id',
        ] as $key) {
            $this->copyInteger($canonical, $key, $value[$key] ?? null);
        }

        $correlation = $this->correlation(
            $value['correlation'] ?? null,
        );

        if ($correlation !== []) {
            $canonical['correlation'] = $correlation;
        }

        $matchedEvent = $this->automationEventReference(
            $value['matched_event'] ?? null,
        );

        if ($matchedEvent !== []) {
            $canonical['matched_event'] = $matchedEvent;
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function continuation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $status = $this->stringValue($value['status'] ?? null);

        if (! in_array($status, ['scheduled', 'failed'], true)) {
            return [];
        }

        $canonical = [
            'status' => $status,
        ];

        foreach (['sequence', 'flow_route_point_id'] as $key) {
            $this->copyInteger($canonical, $key, $value[$key] ?? null);
        }

        $this->copyTimestamp(
            $canonical,
            'scheduled_at',
            $value['scheduled_at'] ?? null,
        );

        if ($status === 'failed') {
            $this->copyTimestamp(
                $canonical,
                'failed_at',
                $value['failed_at'] ?? null,
            );
            $this->copyString(
                $canonical,
                'exception_class',
                $value['exception_class'] ?? null,
            );
            $this->copyString(
                $canonical,
                'exception_message',
                $value['exception_message'] ?? null,
            );
        }

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function correlation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            throw new InvalidArgumentException(
                'FlowRoute progress waiting correlation must be a map.',
            );
        }

        if (count($value) > self::MAX_CORRELATION_ITEMS) {
            throw new InvalidArgumentException(
                'FlowRoute progress waiting correlation contains too many items.',
            );
        }

        $canonical = [];

        foreach ($value as $key => $expected) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'FlowRoute progress waiting correlation contains an invalid key.',
                );
            }

            $key = trim($key);
            $this->assertStringSize($key);
            $canonical[$key] = $this->correlationValue($expected);
        }

        return $canonical;
    }

    private function correlationValue(mixed $value): mixed
    {
        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
        ) {
            return $value;
        }

        if (is_string($value)) {
            $this->assertStringSize($value);

            return $value;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(
                'FlowRoute progress waiting correlation values must be bounded scalars or scalar lists.',
            );
        }

        if (count($value) > self::MAX_CORRELATION_LIST_ITEMS) {
            throw new InvalidArgumentException(
                'FlowRoute progress waiting correlation contains an oversized value list.',
            );
        }

        return array_map(function (mixed $item): mixed {
            if (is_array($item) || is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException(
                    'FlowRoute progress waiting correlation lists may contain only scalar values.',
                );
            }

            if (is_string($item)) {
                $this->assertStringSize($item);
            }

            return $item;
        }, $value);
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
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return;
        }

        $value = (int) $value;

        if ($value >= 0) {
            $target[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copySubjectId(array &$target, mixed $value): void
    {
        if (is_int($value) && $value >= 0) {
            $target['subject_id'] = $value;

            return;
        }

        $value = $this->stringValue($value);

        if ($value !== null) {
            $target['subject_id'] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyTimestamp(array &$target, string $key, mixed $value): void
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format(DateTimeInterface::ATOM);
        }

        $this->copyString($target, $key, $value);
    }

    private function stringValue(mixed $value): ?string
    {
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

    private function assertStringSize(string $value): void
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new InvalidArgumentException(
                'FlowRoute progress metadata contains an oversized string.',
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
                'FlowRoute progress metadata cannot be encoded as JSON.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException(
                'FlowRoute progress metadata exceeds the maximum encoded size.',
            );
        }
    }
}