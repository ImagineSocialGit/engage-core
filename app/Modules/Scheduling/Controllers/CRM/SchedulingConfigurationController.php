<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingConfigurationWriter;
use App\Modules\Scheduling\Services\SchedulingReadService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingConfigurationController extends Controller
{
    public function index(SchedulingReadService $read): View
    {
        return view('crm.scheduling.configuration', [
            'title' => 'Scheduling Configuration',
            'heading' => 'Scheduling Configuration',
            'hosts' => $read->configurationHosts(),
            'services' => $read->configurationServices(),
            'timezones' => timezone_identifiers_list(),
            'defaultTimezone' => config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
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

        $validated = $request->validate($this->hostRules(includeKey: true));

        try {
            $writer->createHost($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return $this->configurationRedirect('host-created');
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

        return $this->configurationRedirect('host-updated');
    }

    public function storeService(
        Request $request,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'key',
            ...$this->serviceFieldNames(),
        ]);

        $validated = $request->validate(
            $this->serviceRules(includeKey: true),
        );

        try {
            $writer->createService($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->configurationException($exception);
        }

        return $this->configurationRedirect('service-created');
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

        return $this->configurationRedirect('service-updated');
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

        return $this->configurationRedirect('assignments-updated');
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
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
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
            'location_type' => [
                'nullable',
                'string',
                Rule::in([
                    BookableService::LOCATION_TYPE_PHONE,
                    BookableService::LOCATION_TYPE_VIRTUAL,
                    BookableService::LOCATION_TYPE_FIXED,
                    BookableService::LOCATION_TYPE_CUSTOMER_SITE,
                ]),
            ],
            'location_label' => ['nullable', 'string', 'max:255'],
            'location_instructions' => ['nullable', 'string', 'max:5000'],
            'location_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_VIRTUAL,
            ],
            'location_address_line_1' => [
                'nullable',
                'required_if:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'string',
                'max:255',
            ],
            'location_address_line_2' => [
                'nullable',
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'string',
                'max:255',
            ],
            'location_city' => [
                'nullable',
                'required_if:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'string',
                'max:255',
            ],
            'location_region' => [
                'nullable',
                'required_if:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'string',
                'max:255',
            ],
            'location_postal_code' => [
                'nullable',
                'required_if:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'string',
                'max:255',
            ],
            'location_country' => [
                'nullable',
                'required_if:location_type,'.BookableService::LOCATION_TYPE_FIXED,
                'prohibited_unless:location_type,'.BookableService::LOCATION_TYPE_FIXED,
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
            'duration_minutes',
            'slot_interval_minutes',
            'buffer_before_minutes',
            'buffer_after_minutes',
            'minimum_notice_minutes',
            'booking_horizon_days',
            'cancellation_notice_minutes',
            'reschedule_notice_minutes',
            'timezone',
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

    private function configurationRedirect(string $event): RedirectResponse
    {
        $message = match ($event) {
            'host-created' => 'Scheduling host created.',
            'host-updated' => 'Scheduling host updated.',
            'service-created' => 'Bookable service created.',
            'service-updated' => 'Bookable service updated.',
            'assignments-updated' => 'Service host assignments updated.',
            default => 'Scheduling configuration updated.',
        };

        return redirect()
            ->route('crm.scheduling.configuration.index')
            ->with('success', $message);
    }
}