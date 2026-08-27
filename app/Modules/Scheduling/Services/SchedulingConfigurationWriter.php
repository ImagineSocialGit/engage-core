<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

class SchedulingConfigurationWriter
{
    public function __construct(
        private readonly SchedulingLocationSnapshotResolver $locationSnapshots,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function createHost(array $attributes): SchedulingHost
    {
        return DB::transaction(function () use ($attributes): SchedulingHost {
            return SchedulingHost::query()->create([
                'key' => $this->requiredKey($attributes['key'] ?? null),
                ...$this->hostAttributes($attributes),
                'hostable_type' => null,
                'hostable_id' => null,
                'source' => SchedulingHost::SOURCE_MANUAL,
                'meta' => null,
            ])->refresh();
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateHost(
        SchedulingHost $host,
        array $attributes,
        string $expectedUpdatedAt,
    ): SchedulingHost {
        return DB::transaction(function () use (
            $host,
            $attributes,
            $expectedUpdatedAt,
        ): SchedulingHost {
            $locked = SchedulingHost::withTrashed()
                ->whereKey($host->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertHostEditable($locked);
            $this->assertFresh($locked, $expectedUpdatedAt, 'Scheduling host');
            $this->assertImmutableKey($locked, $attributes);

            $locked->forceFill($this->hostAttributes($attributes));
            $this->saveWithVersionBump($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function createService(array $attributes): BookableService
    {
        return DB::transaction(function () use ($attributes): BookableService {
            return BookableService::query()->create([
                'key' => $this->requiredKey($attributes['key'] ?? null),
                ...$this->serviceAttributes($attributes),
                'source' => 'manual',
                'provider' => null,
                'external_id' => null,
                'external_url' => null,
                'meta' => null,
            ])->refresh();
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateService(
        BookableService $service,
        array $attributes,
        string $expectedUpdatedAt,
    ): BookableService {
        return DB::transaction(function () use (
            $service,
            $attributes,
            $expectedUpdatedAt,
        ): BookableService {
            $locked = BookableService::withTrashed()
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertServiceEditable($locked);
            $this->assertFresh($locked, $expectedUpdatedAt, 'Bookable service');
            $this->assertImmutableKey($locked, $attributes);

            $locked->forceFill(
                $this->serviceAttributes($attributes, $locked),
            );
            $this->saveWithVersionBump($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $assignments
     */
    public function syncServiceHosts(
        BookableService $service,
        array $assignments,
        string $expectedUpdatedAt,
    ): BookableService {
        return DB::transaction(function () use (
            $service,
            $assignments,
            $expectedUpdatedAt,
        ): BookableService {
            $lockedService = BookableService::withTrashed()
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertServiceEditable($lockedService);
            $this->assertFresh(
                $lockedService,
                $expectedUpdatedAt,
                'Bookable service',
            );

            $submitted = $this->normalizedAssignments($assignments);
            $hostIds = array_keys($submitted);
            $hosts = SchedulingHost::withTrashed()
                ->whereKey($hostIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SchedulingHost $host): int => (int) $host->getKey());

            if ($hosts->count() !== count($hostIds)) {
                throw new DomainException(
                    'One or more selected scheduling hosts no longer exist.',
                );
            }

            $existingAssignments = BookableServiceHost::query()
                ->where('bookable_service_id', $lockedService->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (BookableServiceHost $assignment): int =>
                    (int) $assignment->scheduling_host_id
                );

            foreach ($existingAssignments as $hostId => $assignment) {
                if (! array_key_exists((int) $hostId, $submitted)) {
                    $assignment->forceFill(['is_active' => false])->save();
                }
            }

            foreach ($submitted as $hostId => $attributes) {
                /** @var SchedulingHost $host */
                $host = $hosts->get($hostId);
                $active = (bool) $attributes['is_active'];

                if ($active && (
                    $host->trashed()
                    || $host->status !== SchedulingHost::STATUS_ACTIVE
                )) {
                    throw new DomainException(
                        'Only active scheduling hosts may receive an active service assignment.',
                    );
                }

                /** @var BookableServiceHost|null $assignment */
                $assignment = $existingAssignments->get($hostId);

                if (! $assignment instanceof BookableServiceHost && ! $active) {
                    continue;
                }

                if (! $assignment instanceof BookableServiceHost) {
                    $assignment = new BookableServiceHost([
                        'bookable_service_id' => $lockedService->getKey(),
                        'scheduling_host_id' => $hostId,
                        'meta' => null,
                    ]);
                }

                $assignment->forceFill([
                    'is_active' => $active,
                    'capacity_override' => $attributes['capacity_override'],
                    'sort_order' => $attributes['sort_order'],
                ])->save();
            }

            $this->saveWithVersionBump($lockedService);

            return $lockedService
                ->refresh()
                ->load([
                    'hostAssignments' => fn ($query) => $query
                        ->with('schedulingHost')
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ]);
        });
    }

    public function hostIsEditable(SchedulingHost $host): bool
    {
        return ! $host->trashed()
            && $host->source === SchedulingHost::SOURCE_MANUAL
            && $host->hostable_type === null
            && $host->hostable_id === null;
    }

    public function serviceIsEditable(BookableService $service): bool
    {
        return ! $service->trashed()
            && $service->source === 'manual'
            && $this->nullableString($service->provider) === null
            && $this->nullableString($service->external_id) === null
            && $this->nullableString($service->external_url) === null;
    }

    private function assertHostEditable(SchedulingHost $host): void
    {
        if (! $this->hostIsEditable($host)) {
            throw new DomainException(
                'Provider-, system-, or model-owned scheduling hosts are read-only in CRM configuration.',
            );
        }
    }

    private function assertServiceEditable(BookableService $service): void
    {
        if (! $this->serviceIsEditable($service)) {
            throw new DomainException(
                'Provider- or system-owned bookable services are read-only in CRM configuration.',
            );
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function assertImmutableKey(Model $model, array $attributes): void
    {
        if (! array_key_exists('key', $attributes)) {
            return;
        }

        if ($this->requiredKey($attributes['key']) !== (string) $model->getAttribute('key')) {
            throw new LogicException(
                'Scheduling configuration keys are immutable after creation.',
            );
        }
    }

    private function saveWithVersionBump(Model $model): void
    {
        $current = $model->getOriginal('updated_at');
        $current = $current !== null
            ? CarbonImmutable::parse($current)->utc()
            : null;
        $now = CarbonImmutable::now('UTC');
        $next = $current instanceof CarbonImmutable && ! $now->greaterThan($current)
            ? $current->addSecond()
            : $now;

        $model->forceFill(['updated_at' => $next])->saveQuietly();
    }

    private function assertFresh(
        Model $model,
        string $expectedUpdatedAt,
        string $label,
    ): void {
        $expectedUpdatedAt = trim($expectedUpdatedAt);

        if ($expectedUpdatedAt === '') {
            throw new InvalidArgumentException(
                "{$label} updates require the current record version.",
            );
        }

        try {
            $expected = CarbonImmutable::parse($expectedUpdatedAt)->utc();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "{$label} record version is invalid.",
                previous: $exception,
            );
        }

        $actual = $model->getAttribute('updated_at');

        if ($actual === null || ! CarbonImmutable::instance($actual)->utc()->equalTo($expected)) {
            throw new DomainException(
                "{$label} changed after this form was loaded. Refresh and try again.",
            );
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function hostAttributes(array $attributes): array
    {
        return [
            'name' => $this->requiredString($attributes['name'] ?? null, 'host name'),
            'status' => $this->hostStatus($attributes['status'] ?? null),
            'timezone' => $this->timezone($attributes['timezone'] ?? null),
            'capacity' => $this->positiveInteger($attributes['capacity'] ?? null, 'host capacity'),
            'email' => $this->nullableEmail($attributes['email'] ?? null),
            'phone' => $this->nullableString($attributes['phone'] ?? null),
            'sort_order' => $this->nonNegativeInteger($attributes['sort_order'] ?? 0, 'host sort order'),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function serviceAttributes(
        array $attributes,
        ?BookableService $existing = null,
    ): array {
        $status = $this->serviceStatus($attributes['status'] ?? null);
        $appointment = $this->appointmentConfiguration($attributes, $existing);
        $durationPolicy = $this->durationPolicy($attributes, $existing);
        $isPublic = $status === BookableService::STATUS_ACTIVE
            && (bool) ($attributes['is_public'] ?? false);

        if ($isPublic && ! $appointment['complete']) {
            throw new InvalidArgumentException(
                'Choose how this appointment happens before making it publicly bookable.',
            );
        }

        return [
            'name' => $this->requiredString($attributes['name'] ?? null, 'service name'),
            'description' => $this->nullableString($attributes['description'] ?? null),
            'status' => $status,
            ...$durationPolicy,
            'slot_interval_minutes' => $this->positiveInteger($attributes['slot_interval_minutes'] ?? null, 'slot interval'),
            'buffer_before_minutes' => $this->nonNegativeInteger($attributes['buffer_before_minutes'] ?? 0, 'buffer before'),
            'buffer_after_minutes' => $this->nonNegativeInteger($attributes['buffer_after_minutes'] ?? 0, 'buffer after'),
            'minimum_notice_minutes' => $this->nonNegativeInteger($attributes['minimum_notice_minutes'] ?? 0, 'minimum notice'),
            'booking_horizon_days' => $this->nonNegativeInteger($attributes['booking_horizon_days'] ?? 0, 'booking horizon'),
            'cancellation_notice_minutes' => $this->nonNegativeInteger($attributes['cancellation_notice_minutes'] ?? 0, 'cancellation notice'),
            'reschedule_notice_minutes' => $this->nonNegativeInteger($attributes['reschedule_notice_minutes'] ?? 0, 'reschedule notice'),
            'timezone' => $this->timezone($attributes['timezone'] ?? null),
            'appointment_format' => $appointment['appointment_format'],
            'in_person_arrangement' => $appointment['in_person_arrangement'],
            'remote_method' => $appointment['remote_method'],
            'location_type' => $appointment['location_type'],
            'location_details' => $this->locationDetails(
                attributes: $attributes,
                locationType: $appointment['location_type'],
            ),
            'capacity' => $this->positiveInteger($attributes['capacity'] ?? null, 'service capacity'),
            'requires_confirmation' => (bool) ($attributes['requires_confirmation'] ?? false),
            'is_public' => $isPublic,
            'sort_order' => $this->nonNegativeInteger($attributes['sort_order'] ?? 0, 'service sort order'),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{duration_mode: string, duration_minutes: int, minimum_duration_minutes: int|null, maximum_duration_minutes: int|null}
     */
    private function durationPolicy(
        array $attributes,
        ?BookableService $existing = null,
    ): array {
        $mode = $this->durationMode(
            $attributes['duration_mode']
                ?? $existing?->duration_mode
                ?? BookableService::DURATION_MODE_FIXED,
        );
        $default = $this->positiveInteger(
            $attributes['duration_minutes'] ?? null,
            $mode === BookableService::DURATION_MODE_RANGE
                ? 'default range duration'
                : 'duration',
        );

        if ($mode === BookableService::DURATION_MODE_FIXED) {
            foreach (['minimum_duration_minutes', 'maximum_duration_minutes'] as $field) {
                if (array_key_exists($field, $attributes)
                    && $attributes[$field] !== null
                    && $attributes[$field] !== ''
                ) {
                    throw new InvalidArgumentException(
                        'Fixed-duration services cannot define range duration bounds.',
                    );
                }
            }

            if ($default > 1440) {
                throw new InvalidArgumentException(
                    'Fixed service duration cannot exceed 1440 minutes.',
                );
            }

            return [
                'duration_mode' => $mode,
                'duration_minutes' => $default,
                'minimum_duration_minutes' => null,
                'maximum_duration_minutes' => null,
            ];
        }

        $minimum = $this->positiveInteger(
            $attributes['minimum_duration_minutes'] ?? null,
            'minimum range duration',
        );
        $maximum = $this->positiveInteger(
            $attributes['maximum_duration_minutes'] ?? null,
            'maximum range duration',
        );

        if ($maximum > BookableService::MAX_RANGE_DURATION_MINUTES) {
            throw new InvalidArgumentException(sprintf(
                'Maximum range duration cannot exceed %d minutes.',
                BookableService::MAX_RANGE_DURATION_MINUTES,
            ));
        }

        if ($minimum > $default || $default > $maximum) {
            throw new InvalidArgumentException(
                'Range duration requires minimum <= default <= maximum.',
            );
        }

        return [
            'duration_mode' => $mode,
            'duration_minutes' => $default,
            'minimum_duration_minutes' => $minimum,
            'maximum_duration_minutes' => $maximum,
        ];
    }

    private function durationMode(mixed $value): string
    {
        $value = $this->requiredString($value, 'duration mode');

        if (! in_array($value, BookableService::DURATION_MODES, true)) {
            throw new InvalidArgumentException(
                "Bookable service duration mode [{$value}] is invalid.",
            );
        }

        return $value;
    }

    /**
     * Service authoring uses business-language appointment fields. The legacy
     * location_type input remains accepted only as a compatibility boundary for
     * older tests/importers and is projected into the new hierarchy here.
     *
     * @param array<string, mixed> $attributes
     * @return array{appointment_format: string|null, in_person_arrangement: string|null, remote_method: string|null, location_type: string|null, complete: bool}
     */
    private function appointmentConfiguration(
        array $attributes,
        ?BookableService $existing = null,
    ): array {
        $usesNewFields = array_key_exists('appointment_format', $attributes)
            || array_key_exists('in_person_arrangement', $attributes)
            || array_key_exists('remote_method', $attributes);

        if (! $usesNewFields) {
            $legacyType = $this->locationType(
                $attributes['location_type'] ?? $existing?->location_type,
            );
            $legacy = BookableService::appointmentConfigurationForLocationType($legacyType);

            return [
                ...$legacy,
                'location_type' => $legacyType,
                'complete' => $legacyType !== null,
            ];
        }

        $format = $this->nullableString($attributes['appointment_format'] ?? null);
        $inPersonArrangement = $this->nullableString(
            $attributes['in_person_arrangement'] ?? null,
        );
        $remoteMethod = $this->nullableString($attributes['remote_method'] ?? null);

        if ($format !== null && ! in_array($format, BookableService::APPOINTMENT_FORMATS, true)) {
            throw new InvalidArgumentException(
                "Bookable service appointment format [{$format}] is invalid.",
            );
        }

        if ($inPersonArrangement !== null
            && ! in_array($inPersonArrangement, BookableService::IN_PERSON_ARRANGEMENTS, true)
        ) {
            throw new InvalidArgumentException(
                "Bookable service in-person arrangement [{$inPersonArrangement}] is invalid.",
            );
        }

        if ($remoteMethod !== null
            && ! in_array($remoteMethod, BookableService::REMOTE_METHODS, true)
        ) {
            throw new InvalidArgumentException(
                "Bookable service remote method [{$remoteMethod}] is invalid.",
            );
        }

        if ($format === null) {
            if ($inPersonArrangement !== null || $remoteMethod !== null) {
                throw new InvalidArgumentException(
                    'Choose an appointment format before choosing how the appointment happens.',
                );
            }

            return [
                'appointment_format' => null,
                'in_person_arrangement' => null,
                'remote_method' => null,
                'location_type' => null,
                'complete' => false,
            ];
        }

        if ($format === BookableService::APPOINTMENT_FORMAT_IN_PERSON) {
            if ($remoteMethod !== null) {
                throw new InvalidArgumentException(
                    'In-person appointments cannot use a remote meeting method.',
                );
            }

            $locationType = BookableService::locationTypeForAppointmentConfiguration(
                appointmentFormat: $format,
                inPersonArrangement: $inPersonArrangement,
                remoteMethod: null,
            );

            return [
                'appointment_format' => $format,
                'in_person_arrangement' => $inPersonArrangement,
                'remote_method' => null,
                'location_type' => $locationType,
                'complete' => $locationType !== null,
            ];
        }

        if ($inPersonArrangement !== null) {
            throw new InvalidArgumentException(
                'Remote appointments cannot use an in-person arrangement.',
            );
        }

        $locationType = BookableService::locationTypeForAppointmentConfiguration(
            appointmentFormat: $format,
            inPersonArrangement: null,
            remoteMethod: $remoteMethod,
        );

        return [
            'appointment_format' => $format,
            'in_person_arrangement' => null,
            'remote_method' => $remoteMethod,
            'location_type' => $locationType,
            'complete' => $locationType !== null,
        ];
    }

    private function locationDetails(
        array $attributes,
        ?string $locationType,
    ): ?array {
        if ($locationType === null) {
            $this->assertNoLocationFields($attributes, [
                'location_label',
                'location_url',
                'location_instructions',
                'location_address_line_1',
                'location_address_line_2',
                'location_city',
                'location_region',
                'location_postal_code',
                'location_country',
            ]);

            return null;
        }

        $label = $this->nullableString($attributes['location_label'] ?? null);
        $instructions = $this->nullableString($attributes['location_instructions'] ?? null);

        return match ($locationType) {
            BookableService::LOCATION_TYPE_PHONE => $this->simpleLocationDetails(
                attributes: $attributes,
                label: $label,
                instructions: $instructions,
                forbidden: [
                    'location_url',
                    'location_address_line_1',
                    'location_address_line_2',
                    'location_city',
                    'location_region',
                    'location_postal_code',
                    'location_country',
                ],
            ),
            BookableService::LOCATION_TYPE_VIRTUAL => $this->virtualLocationDetails(
                attributes: $attributes,
                label: $label,
                instructions: $instructions,
            ),
            BookableService::LOCATION_TYPE_FIXED => $this->fixedLocationDetails(
                attributes: $attributes,
                label: $label,
                instructions: $instructions,
            ),
            BookableService::LOCATION_TYPE_CUSTOMER_SITE => $this->simpleLocationDetails(
                attributes: $attributes,
                label: $label,
                instructions: $instructions,
                forbidden: [
                    'location_url',
                    'location_address_line_1',
                    'location_address_line_2',
                    'location_city',
                    'location_region',
                    'location_postal_code',
                    'location_country',
                ],
            ),
            default => throw new LogicException(
                "Unsupported Scheduling location type [{$locationType}].",
            ),
        };
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $forbidden
     * @return array<string, mixed>|null
     */
    private function simpleLocationDetails(
        array $attributes,
        ?string $label,
        ?string $instructions,
        array $forbidden,
    ): ?array {
        $this->assertNoLocationFields($attributes, $forbidden);

        $details = array_filter([
            'label' => $label,
            'instructions' => $instructions,
        ], static fn (mixed $value): bool => $value !== null);

        return $details !== [] ? $details : null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    private function virtualLocationDetails(
        array $attributes,
        ?string $label,
        ?string $instructions,
    ): ?array {
        $this->assertNoLocationFields($attributes, [
            'location_address_line_1',
            'location_address_line_2',
            'location_city',
            'location_region',
            'location_postal_code',
            'location_country',
        ]);

        $url = $this->nullableString($attributes['location_url'] ?? null);

        if ($url !== null) {
            $parts = parse_url($url);
            $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
                ? strtolower($parts['scheme'])
                : null;
            $host = is_array($parts) && is_string($parts['host'] ?? null)
                ? trim($parts['host'])
                : null;

            if (! in_array($scheme, ['http', 'https'], true)
                || ! is_string($host)
                || $host === ''
            ) {
                throw new InvalidArgumentException(
                    'Virtual Scheduling location URLs must be absolute HTTP or HTTPS URLs.',
                );
            }
        }

        $details = array_filter([
            'label' => $label,
            'url' => $url,
            'instructions' => $instructions,
        ], static fn (mixed $value): bool => $value !== null);

        return $details !== [] ? $details : null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function fixedLocationDetails(
        array $attributes,
        ?string $label,
        ?string $instructions,
    ): array {
        $this->assertNoLocationFields($attributes, ['location_url']);

        return $this->locationSnapshots->normalizeAddress(
            type: BookableService::LOCATION_TYPE_FIXED,
            input: [
                'address_line_1' => $this->requiredString(
                    $attributes['location_address_line_1'] ?? null,
                    'fixed-location address line 1',
                ),
                'address_line_2' => $this->nullableString(
                    $attributes['location_address_line_2'] ?? null,
                ),
                'city' => $this->requiredString(
                    $attributes['location_city'] ?? null,
                    'fixed-location city',
                ),
                'region' => $this->requiredString(
                    $attributes['location_region'] ?? null,
                    'fixed-location region',
                ),
                'postal_code' => $this->requiredString(
                    $attributes['location_postal_code'] ?? null,
                    'fixed-location postal code',
                ),
                'country' => $this->requiredString(
                    $attributes['location_country'] ?? null,
                    'fixed-location country',
                ),
            ],
            label: $label,
            instructions: $instructions,
        )->details ?? throw new LogicException(
            'Fixed Scheduling locations must produce location details.',
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $fields
     */
    private function assertNoLocationFields(
        array $attributes,
        array $fields,
    ): void {
        $submitted = array_values(array_filter(
            $fields,
            fn (string $field): bool => $this->nullableString(
                $attributes[$field] ?? null,
            ) !== null,
        ));

        if ($submitted === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Location type does not accept field(s): [%s].',
            implode(', ', $submitted),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assignments
     * @return array<int, array{is_active: bool, capacity_override: int|null, sort_order: int}>
     */
    private function normalizedAssignments(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $attributes) {
            if (! is_array($attributes)) {
                throw new InvalidArgumentException(
                    'Service host assignments must be arrays.',
                );
            }

            $hostId = $this->positiveInteger(
                $attributes['scheduling_host_id'] ?? null,
                'scheduling host ID',
            );

            if (array_key_exists($hostId, $normalized)) {
                throw new InvalidArgumentException(
                    'Each scheduling host may appear only once in an assignment submission.',
                );
            }

            $capacityOverride = $attributes['capacity_override'] ?? null;

            $normalized[$hostId] = [
                'is_active' => (bool) ($attributes['is_active'] ?? false),
                'capacity_override' => $capacityOverride === null || $capacityOverride === ''
                    ? null
                    : $this->positiveInteger($capacityOverride, 'assignment capacity override'),
                'sort_order' => $this->nonNegativeInteger(
                    $attributes['sort_order'] ?? 0,
                    'assignment sort order',
                ),
            ];
        }

        return $normalized;
    }

    private function requiredKey(mixed $value): string
    {
        $value = $this->requiredString($value, 'configuration key');

        if (preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Scheduling configuration keys must use lowercase letters, numbers, hyphens, or underscores.',
            );
        }

        return $value;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("A non-empty {$label} is required.");
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableEmail(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value !== null ? strtolower($value) : null;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException("{$label} must be at least 1.");
        }

        return (int) $value;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return (int) $value;
    }

    private function timezone(mixed $value): string
    {
        $value = $this->requiredString($value, 'timezone');

        if (! in_array($value, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Timezone [{$value}] is invalid.");
        }

        return $value;
    }

    private function locationType(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (! in_array($value, [
            BookableService::LOCATION_TYPE_PHONE,
            BookableService::LOCATION_TYPE_VIRTUAL,
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
        ], true)) {
            throw new InvalidArgumentException(
                "Bookable service location type [{$value}] is invalid.",
            );
        }

        return $value;
    }

    private function hostStatus(mixed $value): string
    {
        $value = $this->requiredString($value, 'host status');

        if (! in_array($value, [
            SchedulingHost::STATUS_ACTIVE,
            SchedulingHost::STATUS_INACTIVE,
            SchedulingHost::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException("Scheduling host status [{$value}] is invalid.");
        }

        return $value;
    }

    private function serviceStatus(mixed $value): string
    {
        $value = $this->requiredString($value, 'service status');

        if (! in_array($value, [
            BookableService::STATUS_ACTIVE,
            BookableService::STATUS_INACTIVE,
            BookableService::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException("Bookable service status [{$value}] is invalid.");
        }

        return $value;
    }
}