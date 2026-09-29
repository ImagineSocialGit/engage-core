<?php

namespace App\Modules\Events\Validation;

use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventReadinessRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class EventsSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'events';

    private const DEFINITIONS_SOURCE = 'events.definitions';

    private const READINESS_SOURCE = 'events.readiness';

    private const RUNTIME_SOURCE = 'events.runtime';

    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
        private readonly EventReadinessRegistry $readiness,
    ) {}

    public function findings(): iterable
    {
        try {
            $this->definitions->all();
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'events.definition_registry_invalid',
                message: $exception->getMessage(),
                source: self::DEFINITIONS_SOURCE,
                path: self::DEFINITIONS_SOURCE,
                context: ['exception' => $exception::class],
            );

            return;
        }

        try {
            $capabilities = $this->readiness->capabilities();
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'events.readiness_registry_invalid',
                message: $exception->getMessage(),
                source: self::READINESS_SOURCE,
                path: 'events.readiness_contributors',
                context: ['exception' => $exception::class],
            );

            return;
        }

        if (! in_array(
            CoreEventReadinessContributor::CAPABILITY,
            $capabilities,
            true,
        )) {
            yield $this->error(
                code: 'events.core_readiness_missing',
                message: 'Events requires the universal core readiness capability.',
                source: self::READINESS_SOURCE,
                path: 'events.readiness_contributors',
                context: [
                    'capability' => CoreEventReadinessContributor::CAPABILITY,
                ],
            );
        }

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
            'livestream',
        )) {
            yield $this->error(
                code: 'events.livestream_reference_type_missing',
                message: 'Events requires an active livestream external-reference type for virtual Event readiness.',
                source: self::DEFINITIONS_SOURCE,
                path: 'events.definitions.external_reference_type.livestream',
                context: [
                    'category' => EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
                    'key' => 'livestream',
                ],
            );
        }

        if ($this->definitions->keys(
            EventDefinitionContribution::CATEGORY_ATTENDANCE_SOURCE,
        ) === []) {
            yield $this->error(
                code: 'events.attendance_source_missing',
                message: 'Events requires at least one active attendance source.',
                source: self::DEFINITIONS_SOURCE,
                path: 'events.definitions.attendance_source',
            );
        }

        yield from $this->validateEvents();
        yield from $this->validateExternalReferences();
        yield from $this->validateStakeholders();
        yield from $this->validateAttendances();
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateEvents(): iterable
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        $canEvaluateCoreReadiness = Schema::hasTable('event_external_references');

        foreach (DB::table('events')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->cursor() as $row
        ) {
            $eventId = (int) $row->id;
            $typeKey = $this->nullableString($row->type_key ?? null);
            $status = $this->nullableString($row->status ?? null);
            $attendanceMode = $this->nullableString($row->attendance_mode ?? null);

            if ($typeKey !== null && ! $this->definitions->has(
                EventDefinitionContribution::CATEGORY_EVENT_TYPE,
                $typeKey,
            )) {
                yield $this->runtimeError(
                    code: 'events.event_type_unregistered',
                    eventId: $eventId,
                    field: 'type_key',
                    context: ['type_key' => $typeKey],
                );
            }

            if ($status === null || ! in_array($status, EventStatus::values(), true)) {
                yield $this->runtimeError(
                    code: 'events.event_status_invalid',
                    eventId: $eventId,
                    field: 'status',
                    context: ['status' => $status],
                );

                continue;
            }

            if ($attendanceMode === null
                || ! in_array($attendanceMode, EventAttendanceMode::values(), true)
            ) {
                yield $this->runtimeError(
                    code: 'events.attendance_mode_invalid',
                    eventId: $eventId,
                    field: 'attendance_mode',
                    context: ['attendance_mode' => $attendanceMode],
                );
            }

            if ($status !== EventStatus::Upcoming->value
                || ! $canEvaluateCoreReadiness
            ) {
                continue;
            }

            $event = Event::query()->find($eventId);

            if (! $event instanceof Event) {
                continue;
            }

            $readiness = $this->readiness->evaluate(
                $event,
                CoreEventReadinessContributor::CAPABILITY,
            );

            if (! $readiness->ready()) {
                yield $this->runtimeError(
                    code: 'events.upcoming_core_readiness_failed',
                    eventId: $eventId,
                    field: 'status',
                    context: [
                        'status' => EventStatus::Upcoming->value,
                        'readiness_codes' => $readiness->codes(),
                    ],
                );
            }
        }

        if (! $canEvaluateCoreReadiness) {
            return;
        }

        foreach (DB::table('events as events')
            ->leftJoin(
                'event_external_references as primary_reference',
                'primary_reference.id',
                '=',
                'events.primary_external_reference_id',
            )
            ->whereNull('events.deleted_at')
            ->whereNotNull('events.primary_external_reference_id')
            ->orderBy('events.id')
            ->select([
                'events.id as event_id',
                'events.primary_external_reference_id',
                'primary_reference.event_id as reference_event_id',
                'primary_reference.deleted_at as reference_deleted_at',
            ])
            ->cursor() as $row
        ) {
            $eventId = (int) $row->event_id;
            $referenceId = (int) $row->primary_external_reference_id;

            if ($row->reference_event_id !== null
                && (int) $row->reference_event_id !== $eventId
            ) {
                yield $this->runtimeError(
                    code: 'events.primary_external_reference_event_mismatch',
                    eventId: $eventId,
                    field: 'primary_external_reference_id',
                    context: [
                        'primary_external_reference_id' => $referenceId,
                        'reference_event_id' => (int) $row->reference_event_id,
                    ],
                );
            }

            if ($row->reference_deleted_at !== null) {
                yield $this->runtimeError(
                    code: 'events.primary_external_reference_inactive',
                    eventId: $eventId,
                    field: 'primary_external_reference_id',
                    context: [
                        'primary_external_reference_id' => $referenceId,
                    ],
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateExternalReferences(): iterable
    {
        if (! Schema::hasTable('event_external_references')
            || ! Schema::hasTable('events')
        ) {
            return;
        }

        foreach (DB::table('event_external_references as event_refs')
            ->join('events', 'events.id', '=', 'event_refs.event_id')
            ->whereNull('event_refs.deleted_at')
            ->whereNull('events.deleted_at')
            ->orderBy('event_refs.id')
            ->select([
                'event_refs.id',
                'event_refs.event_id',
                'event_refs.provider_key',
                'event_refs.reference_type',
                'event_refs.external_id',
                'event_refs.url',
            ])
            ->cursor() as $row
        ) {
            $referenceId = (int) $row->id;
            $eventId = (int) $row->event_id;
            $providerKey = $this->nullableString($row->provider_key ?? null);
            $referenceType = $this->nullableString($row->reference_type ?? null);

            if ($providerKey === null || ! $this->definitions->has(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_PROVIDER,
                $providerKey,
            )) {
                yield $this->referenceError(
                    code: 'events.external_reference_provider_unregistered',
                    referenceId: $referenceId,
                    eventId: $eventId,
                    field: 'provider_key',
                    context: ['provider_key' => $providerKey],
                );
            }

            if ($referenceType === null || ! $this->definitions->has(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
                $referenceType,
            )) {
                yield $this->referenceError(
                    code: 'events.external_reference_type_unregistered',
                    referenceId: $referenceId,
                    eventId: $eventId,
                    field: 'reference_type',
                    context: ['reference_type' => $referenceType],
                );
            }

            if ($this->nullableString($row->external_id ?? null) === null
                && $this->nullableString($row->url ?? null) === null
            ) {
                yield $this->referenceError(
                    code: 'events.external_reference_identity_missing',
                    referenceId: $referenceId,
                    eventId: $eventId,
                    field: 'external_id',
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateStakeholders(): iterable
    {
        if (! Schema::hasTable('event_stakeholders')
            || ! Schema::hasTable('events')
        ) {
            return;
        }

        foreach (DB::table('event_stakeholders as stakeholders')
            ->join('events', 'events.id', '=', 'stakeholders.event_id')
            ->whereNull('stakeholders.deleted_at')
            ->whereNull('events.deleted_at')
            ->orderBy('stakeholders.id')
            ->select([
                'stakeholders.id',
                'stakeholders.event_id',
                'stakeholders.role_key',
            ])
            ->cursor() as $row
        ) {
            $roleKey = $this->nullableString($row->role_key ?? null);

            if ($roleKey !== null && $this->definitions->has(
                EventDefinitionContribution::CATEGORY_STAKEHOLDER_ROLE,
                $roleKey,
            )) {
                continue;
            }

            yield $this->error(
                code: 'events.stakeholder_role_unregistered',
                message: 'An Event stakeholder references an inactive or unregistered role.',
                source: self::RUNTIME_SOURCE,
                path: 'event_stakeholders.'.(int) $row->id.'.role_key',
                context: [
                    'event_stakeholder_id' => (int) $row->id,
                    'event_id' => (int) $row->event_id,
                    'role_key' => $roleKey,
                ],
            );
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function validateAttendances(): iterable
    {
        if (! Schema::hasTable('event_attendances')
            || ! Schema::hasTable('events')
        ) {
            return;
        }

        foreach (DB::table('event_attendances as attendances')
            ->join('events', 'events.id', '=', 'attendances.event_id')
            ->whereNull('attendances.deleted_at')
            ->whereNull('events.deleted_at')
            ->orderBy('attendances.id')
            ->select([
                'attendances.id',
                'attendances.event_id',
                'attendances.status',
                'attendances.source_key',
            ])
            ->cursor() as $row
        ) {
            $attendanceId = (int) $row->id;
            $eventId = (int) $row->event_id;
            $status = $this->nullableString($row->status ?? null);
            $sourceKey = $this->nullableString($row->source_key ?? null);

            if ($status === null
                || ! in_array($status, EventAttendanceStatus::values(), true)
            ) {
                yield $this->error(
                    code: 'events.attendance_status_invalid',
                    message: 'An Event attendance row has an invalid status.',
                    source: self::RUNTIME_SOURCE,
                    path: "event_attendances.{$attendanceId}.status",
                    context: [
                        'event_attendance_id' => $attendanceId,
                        'event_id' => $eventId,
                        'status' => $status,
                    ],
                );
            }

            if ($sourceKey === null || ! $this->definitions->has(
                EventDefinitionContribution::CATEGORY_ATTENDANCE_SOURCE,
                $sourceKey,
            )) {
                yield $this->error(
                    code: 'events.attendance_source_unregistered',
                    message: 'An Event attendance row references an inactive or unregistered source.',
                    source: self::RUNTIME_SOURCE,
                    path: "event_attendances.{$attendanceId}.source_key",
                    context: [
                        'event_attendance_id' => $attendanceId,
                        'event_id' => $eventId,
                        'source_key' => $sourceKey,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runtimeError(
        string $code,
        int $eventId,
        string $field,
        array $context = [],
    ): SetupValidationFinding {
        return $this->error(
            code: $code,
            message: 'Persisted Event state is inconsistent with the active Events runtime contract.',
            source: self::RUNTIME_SOURCE,
            path: "events.{$eventId}.{$field}",
            context: ['event_id' => $eventId] + $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function referenceError(
        string $code,
        int $referenceId,
        int $eventId,
        string $field,
        array $context = [],
    ): SetupValidationFinding {
        return $this->error(
            code: $code,
            message: 'Persisted Event external-reference state is inconsistent with the active Events runtime contract.',
            source: self::RUNTIME_SOURCE,
            path: "event_external_references.{$referenceId}.{$field}",
            context: [
                'event_external_reference_id' => $referenceId,
                'event_id' => $eventId,
            ] + $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $source,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: $source,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}