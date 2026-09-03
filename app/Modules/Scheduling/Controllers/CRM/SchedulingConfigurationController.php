<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingConfigurationWriter;
use App\Modules\Scheduling\Services\SchedulingReadService;
use App\Modules\Scheduling\Services\SchedulingSetupReadiness;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingConfigurationController extends Controller
{
    public function index(
        SchedulingSetupReadiness $readiness,
    ): View {
        return view('crm.scheduling.configuration', [
            'title' => 'Scheduling Setup',
            'heading' => 'Scheduling Setup',
            'readiness' => $readiness->summary(),
        ]);
    }

    public function services(SchedulingReadService $read): View
    {
        return view('crm.scheduling.services.index', [
            'title' => 'Scheduling Services',
            'heading' => 'Services',
            'services' => $read->configurationServices(),
        ]);
    }

    public function editService(
        BookableService $bookableService,
        SchedulingReadService $read,
    ): View {
        $service = $read->configurationService($bookableService);
        $locationDetails = is_array($service->location_details)
            ? $service->location_details
            : [];
        $locationAddress = is_array($locationDetails['address'] ?? null)
            ? $locationDetails['address']
            : [];
        $assignmentByHost = $service->hostAssignments
            ->keyBy('scheduling_host_id');

        $assignmentRows = $read->configurationHosts()
            ->map(function (SchedulingHost $host) use ($assignmentByHost): array {
                $assignment = $assignmentByHost->get($host->getKey());
                $active = (bool) $assignment?->is_active;

                return [
                    'id' => (int) $host->getKey(),
                    'name' => (string) $host->name,
                    'status' => (string) $host->status,
                    'timezone' => (string) $host->timezone,
                    'selectable' => $host->status === SchedulingHost::STATUS_ACTIVE || $active,
                    'active' => $active,
                    'capacity_override' => $assignment?->capacity_override,
                    'sort_order' => (int) ($assignment?->sort_order ?? $host->sort_order),
                ];
            })
            ->values()
            ->all();

        return view('crm.scheduling.services.edit', [
            'title' => 'Edit Scheduling Service',
            'heading' => $service->name,
            'service' => $service,
            'serviceEditable' => (bool) $service->getAttribute('crm_editable'),
            'serviceStatuses' => [
                BookableService::STATUS_ACTIVE,
                BookableService::STATUS_INACTIVE,
                BookableService::STATUS_ARCHIVED,
            ],
            'timezones' => timezone_identifiers_list(),
            'appointmentConfiguration' => $service->resolvedAppointmentConfiguration(),
            'locationDetails' => $locationDetails,
            'locationAddress' => $locationAddress,
            'assignmentRows' => $assignmentRows,
            'maxRangeDurationMinutes' => BookableService::MAX_RANGE_DURATION_MINUTES,
        ]);
    }

    public function staff(SchedulingReadService $read): View
    {
        return view('crm.scheduling.staff.index', [
            'title' => 'Scheduling Staff',
            'heading' => 'Staff & Providers',
            'hosts' => $read->configurationHosts(),
            'hostStatuses' => [
                SchedulingHost::STATUS_ACTIVE,
                SchedulingHost::STATUS_INACTIVE,
                SchedulingHost::STATUS_ARCHIVED,
            ],
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function storeHost(
        Request $request,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'key',
            'name',
            'status',
            'timezone',
            'capacity',
            'email',
            'phone',
            'sort_order',
        ]);

        $validated = validator(
            $this->hostCreatePayload($request),
            $this->hostRules(includeKey: true),
        )->validate();

        try {
            $writer->createHost($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return redirect()
            ->route('crm.scheduling.configuration.staff.index')
            ->with('success', 'Scheduling staff member created.');
    }

    public function updateHost(
        Request $request,
        SchedulingHost $schedulingHost,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            'name',
            'status',
            'timezone',
            'capacity',
            'email',
            'phone',
            'sort_order',
        ]);

        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            ...$this->hostRules(includeKey: false),
        ]);

        try {
            $writer->updateHost(
                host: $schedulingHost,
                attributes: $validated,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return redirect()
            ->route('crm.scheduling.configuration.staff.index')
            ->with('success', 'Scheduling staff member updated.');
    }

    public function storeService(
        Request $request,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'key',
            ...$this->serviceFieldNames(),
        ]);

        $validated = validator(
            $this->serviceCreatePayload($request),
            $this->serviceRules(includeKey: true),
        )->validate();

        try {
            $writer->createService($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return redirect()
            ->route('crm.scheduling.configuration.services.index')
            ->with('success', 'Scheduling service created.');
    }

    public function updateService(
        Request $request,
        BookableService $bookableService,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            ...$this->serviceFieldNames(),
        ]);

        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            ...$this->serviceRules(includeKey: false),
        ]);

        try {
            $writer->updateService(
                service: $bookableService,
                attributes: $validated,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return redirect()
            ->route('crm.scheduling.configuration.services.edit', $bookableService)
            ->with('success', 'Scheduling service updated.');
    }

    public function updateServiceHosts(
        Request $request,
        BookableService $bookableService,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            'assignments',
        ]);
        $this->assertAssignmentFields($request);

        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['array'],
            'assignments.*.scheduling_host_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('scheduling_hosts', 'id'),
            ],
            'assignments.*.is_active' => ['required', 'boolean'],
            'assignments.*.capacity_override' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'assignments.*.sort_order' => [
                'required',
                'integer',
                'min:0',
                'max:100000',
            ],
        ]);

        try {
            $writer->syncServiceHosts(
                service: $bookableService,
                assignments: $validated['assignments'] ?? [],
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return redirect()
            ->route('crm.scheduling.configuration.services.edit', $bookableService)
            ->with('success', 'Service staff assignments updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function hostCreatePayload(Request $request): array
    {
        $payload = $request->all();

        if (! is_string($payload['key'] ?? null) || trim((string) $payload['key']) === '') {
            $payload['key'] = $this->generatedHostKey((string) ($payload['name'] ?? ''));
        }

        $payload += [
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => $this->defaultTimezone(),
            'capacity' => 1,
            'email' => null,
            'phone' => null,
            'sort_order' => $this->nextHostSortOrder(),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceCreatePayload(Request $request): array
    {
        $payload = $request->all();

        if (! is_string($payload['key'] ?? null) || trim((string) $payload['key']) === '') {
            $payload['key'] = $this->generatedServiceKey((string) ($payload['name'] ?? ''));
        }

        $payload += [
            'description' => null,
            'status' => BookableService::STATUS_ACTIVE,
            'duration_mode' => BookableService::DURATION_MODE_FIXED,
            'duration_minutes' => 60,
            'minimum_duration_minutes' => null,
            'maximum_duration_minutes' => null,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 60,
            'cancellation_notice_minutes' => 0,
            'reschedule_notice_minutes' => 0,
            'timezone' => $this->defaultTimezone(),
            'location_type' => null,
            'location_label' => null,
            'location_instructions' => null,
            'location_url' => null,
            'location_address_line_1' => null,
            'location_address_line_2' => null,
            'location_city' => null,
            'location_region' => null,
            'location_postal_code' => null,
            'location_country' => null,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => false,
            'sort_order' => $this->nextServiceSortOrder(),
        ];

        return $payload;
    }

    private function generatedHostKey(string $name): string
    {
        return $this->generatedKey(
            name: $name,
            fallback: 'staff',
            exists: fn (string $key): bool => SchedulingHost::withTrashed()
                ->where('key', $key)
                ->exists(),
        );
    }

    private function generatedServiceKey(string $name): string
    {
        return $this->generatedKey(
            name: $name,
            fallback: 'service',
            exists: fn (string $key): bool => BookableService::withTrashed()
                ->where('key', $key)
                ->exists(),
        );
    }

    /**
     * @param callable(string): bool $exists
     */
    private function generatedKey(
        string $name,
        string $fallback,
        callable $exists,
    ): string {
        $base = Str::slug($name, '_');
        $base = $base !== '' ? $base : $fallback;
        $base = mb_substr($base, 0, 170);
        $candidate = $base;
        $sequence = 2;

        while ($exists($candidate)) {
            $suffix = '_'.$sequence;
            $candidate = mb_substr(
                $base,
                0,
                max(1, 191 - mb_strlen($suffix)),
            ).$suffix;
            $sequence++;
        }

        return $candidate;
    }

    private function defaultTimezone(): string
    {
        $timezone = config('client.timezone', config('app.timezone', 'UTC'));

        return is_string($timezone)
            && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : 'UTC';
    }

    private function nextHostSortOrder(): int
    {
        return min(
            100000,
            ((int) SchedulingHost::withTrashed()->max('sort_order')) + 10,
        );
    }

    private function nextServiceSortOrder(): int
    {
        return min(
            100000,
            ((int) BookableService::withTrashed()->max('sort_order')) + 10,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hostRules(bool $includeKey): array
    {
        return array_filter([
            'key' => $includeKey
                ? [
                    'required',
                    'string',
                    'max:191',
                    'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                    Rule::unique('scheduling_hosts', 'key'),
                ]
                : null,
            'name' => ['required', 'string', 'max:255'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    SchedulingHost::STATUS_ACTIVE,
                    SchedulingHost::STATUS_INACTIVE,
                    SchedulingHost::STATUS_ARCHIVED,
                ]),
            ],
            'timezone' => [
                'required',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], static fn (mixed $rules): bool => is_array($rules));
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRules(bool $includeKey): array
    {
        return array_filter([
            'key' => $includeKey
                ? [
                    'required',
                    'string',
                    'max:191',
                    'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                    Rule::unique('bookable_services', 'key'),
                ]
                : null,
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    BookableService::STATUS_ACTIVE,
                    BookableService::STATUS_INACTIVE,
                    BookableService::STATUS_ARCHIVED,
                ]),
            ],
            'duration_mode' => [
                'required',
                'string',
                Rule::in(BookableService::DURATION_MODES),
            ],
            'duration_minutes' => [
                'required',
                'integer',
                'min:1',
                'max:'.BookableService::MAX_RANGE_DURATION_MINUTES,
            ],
            'minimum_duration_minutes' => [
                'nullable',
                'required_if:duration_mode,'.BookableService::DURATION_MODE_RANGE,
                'prohibited_unless:duration_mode,'.BookableService::DURATION_MODE_RANGE,
                'integer',
                'min:1',
                'max:'.BookableService::MAX_RANGE_DURATION_MINUTES,
            ],
            'maximum_duration_minutes' => [
                'nullable',
                'required_if:duration_mode,'.BookableService::DURATION_MODE_RANGE,
                'prohibited_unless:duration_mode,'.BookableService::DURATION_MODE_RANGE,
                'integer',
                'min:1',
                'max:'.BookableService::MAX_RANGE_DURATION_MINUTES,
            ],
            'slot_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'minimum_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'booking_horizon_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'cancellation_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'reschedule_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'timezone' => [
                'required',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
            'appointment_format' => [
                'nullable',
                'string',
                Rule::in(BookableService::APPOINTMENT_FORMATS),
            ],
            'in_person_arrangement' => [
                'nullable',
                'string',
                Rule::in(BookableService::IN_PERSON_ARRANGEMENTS),
            ],
            'remote_method' => [
                'nullable',
                'string',
                Rule::in(BookableService::REMOTE_METHODS),
            ],
            // Backward-compatible request boundary only. The CRM surface no
            // longer authors this internal runtime classification directly.
            'location_type' => [
                'nullable',
                'string',
                Rule::in(BookableService::LOCATION_TYPES),
            ],
            'location_label' => ['nullable', 'string', 'max:255'],
            'location_instructions' => ['nullable', 'string', 'max:5000'],
            'location_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesVirtualMeeting()),
            ],
            'location_address_line_1' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->requestUsesBusinessLocation()),
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'max:255',
            ],
            'location_address_line_2' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'max:255',
            ],
            'location_city' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->requestUsesBusinessLocation()),
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'max:255',
            ],
            'location_region' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->requestUsesBusinessLocation()),
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'max:255',
            ],
            'location_postal_code' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->requestUsesBusinessLocation()),
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'max:255',
            ],
            'location_country' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->requestUsesBusinessLocation()),
                Rule::prohibitedIf(fn (): bool => ! $this->requestUsesBusinessLocation()),
                'string',
                'size:2',
                'regex:/^[A-Za-z]{2}$/',
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'requires_confirmation' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], static fn (mixed $rules): bool => is_array($rules));
    }

    /**
     * @return array<int, string>
     */
    private function serviceFieldNames(): array
    {
        return [
            'name',
            'description',
            'status',
            'duration_mode',
            'duration_minutes',
            'minimum_duration_minutes',
            'maximum_duration_minutes',
            'slot_interval_minutes',
            'buffer_before_minutes',
            'buffer_after_minutes',
            'minimum_notice_minutes',
            'booking_horizon_days',
            'cancellation_notice_minutes',
            'reschedule_notice_minutes',
            'timezone',
            'appointment_format',
            'in_person_arrangement',
            'remote_method',
            'location_type',
            'location_label',
            'location_instructions',
            'location_url',
            'location_address_line_1',
            'location_address_line_2',
            'location_city',
            'location_region',
            'location_postal_code',
            'location_country',
            'capacity',
            'requires_confirmation',
            'is_public',
            'sort_order',
        ];
    }

    private function requestUsesVirtualMeeting(): bool
    {
        $legacyLocationType = request()->input('location_type');

        if (is_string($legacyLocationType) && trim($legacyLocationType) !== '') {
            return $legacyLocationType === BookableService::LOCATION_TYPE_VIRTUAL;
        }

        return request()->input('appointment_format') === BookableService::APPOINTMENT_FORMAT_REMOTE
            && request()->input('remote_method') === BookableService::REMOTE_METHOD_VIRTUAL_MEETING;
    }

    private function requestUsesBusinessLocation(): bool
    {
        $legacyLocationType = request()->input('location_type');

        if (is_string($legacyLocationType) && trim($legacyLocationType) !== '') {
            return $legacyLocationType === BookableService::LOCATION_TYPE_FIXED;
        }

        return request()->input('appointment_format') === BookableService::APPOINTMENT_FORMAT_IN_PERSON
            && request()->input('in_person_arrangement') === BookableService::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function assertAllowedFields(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(
            array_keys($request->all()),
            [...$allowed, '_token', '_method'],
        ));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'configuration' => 'Unsupported configuration fields were submitted.',
            ]);
        }
    }

    private function assertAssignmentFields(Request $request): void
    {
        $assignments = $request->input('assignments', []);

        if ($assignments === null || $assignments === '') {
            return;
        }

        if (! is_array($assignments)) {
            return;
        }

        foreach ($assignments as $index => $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $unexpected = array_values(array_diff(
                array_keys($assignment),
                [
                    'scheduling_host_id',
                    'is_active',
                    'capacity_override',
                    'sort_order',
                ],
            ));

            if ($unexpected !== []) {
                throw ValidationException::withMessages([
                    "assignments.{$index}" => 'Unsupported assignment fields were submitted.',
                ]);
            }
        }
    }

    private function configurationException(\Throwable $exception): ValidationException
    {
        return ValidationException::withMessages([
            'configuration' => $exception->getMessage(),
        ]);
    }

}