<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingAvailabilityController extends Controller
{
    public function index(
        Request $request,
        SchedulingReadService $read,
    ): View {
        $validated = $request->validate([
            'preview_service_id' => [
                'nullable',
                'integer',
                Rule::exists('bookable_services', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', BookableService::STATUS_ACTIVE)),
            ],
            'preview_host_id' => [
                'nullable',
                'integer',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', SchedulingHost::STATUS_ACTIVE)),
            ],
            'preview_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $services = $read->configurationServices();
        $hosts = $read->configurationHosts();
        $windows = $read->configurationAvailabilityWindows();
        $previewService = isset($validated['preview_service_id'])
            ? BookableService::query()
                ->whereKey($validated['preview_service_id'])
                ->where('status', BookableService::STATUS_ACTIVE)
                ->first()
            : null;
        $previewHosts = $previewService instanceof BookableService
            ? $read->eligibleHosts($previewService)
            : $hosts
                ->where('status', SchedulingHost::STATUS_ACTIVE)
                ->values();
        $previewHost = null;

        if (isset($validated['preview_host_id'])) {
            $previewHost = $previewHosts->firstWhere(
                'id',
                (int) $validated['preview_host_id'],
            );

            if (! $previewHost instanceof SchedulingHost) {
                throw ValidationException::withMessages([
                    'preview_host_id' => 'The selected host is not actively assigned to that service.',
                ]);
            }
        }

        $previewDate = $validated['preview_date']
            ?? CarbonImmutable::now(
                $previewService?->timezone
                    ?? config('client.timezone', config('app.timezone', 'UTC')),
            )->toDateString();
        $previewSlots = [];

        if ($previewService instanceof BookableService
            && (! $read->serviceRequiresHost($previewService)
                || $previewHost instanceof SchedulingHost)
        ) {
            $previewSlots = $read->availabilityForDate(
                service: $previewService,
                date: CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $previewDate,
                    $previewService->timezone,
                ),
                host: $previewHost,
            );
        }

        return view('crm.scheduling.availability', [
            'title' => 'Scheduling Availability',
            'heading' => 'Scheduling Availability',
            'services' => $services,
            'hosts' => $hosts,
            'windows' => $windows,
            'timezones' => timezone_identifiers_list(),
            'defaultTimezone' => config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
            'previewService' => $previewService,
            'previewHosts' => $previewHosts,
            'previewHost' => $previewHost,
            'previewDate' => $previewDate,
            'previewSlots' => $previewSlots,
        ]);
    }

    public function store(
        Request $request,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, $this->availabilityFieldNames());
        $validated = $request->validate($this->availabilityRules());

        try {
            $writer->create($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('created');
    }

    public function update(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            ...$this->availabilityFieldNames(),
        ]);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            ...$this->availabilityRules(),
        ]);

        try {
            $writer->update(
                window: $availabilityWindow,
                attributes: $validated,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('updated');
    }

    public function archive(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, ['current_version']);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
        ]);

        try {
            $writer->archive(
                window: $availabilityWindow,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('archived');
    }

    public function restore(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, ['current_version']);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
        ]);

        try {
            $writer->restore(
                window: $availabilityWindow,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('restored');
    }

    /**
     * @return array<string, mixed>
     */
    private function availabilityRules(): array
    {
        return [
            'scope' => [
                'required',
                'string',
                Rule::in([
                    SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE,
                    SchedulingAvailabilityConfigurationWriter::SCOPE_HOST,
                    SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE_HOST,
                ]),
            ],
            'bookable_service_id' => [
                'nullable',
                'integer',
                'required_if:scope,service,service_host',
                'prohibited_if:scope,host',
                Rule::exists('bookable_services', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'scheduling_host_id' => [
                'nullable',
                'integer',
                'required_if:scope,host,service_host',
                'prohibited_if:scope,service',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'window_type' => [
                'required',
                'string',
                Rule::enum(SchedulingAvailabilityWindowType::class),
            ],
            'timezone' => [
                'required',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
            'weekday' => [
                'nullable',
                'integer',
                'between:0,6',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'start_time' => [
                'nullable',
                'date_format:H:i',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'end_time' => [
                'nullable',
                'date_format:H:i',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'local_starts_at' => [
                'nullable',
                'date_format:Y-m-d\\TH:i',
                'required_if:window_type,absolute',
                'prohibited_if:window_type,weekly',
            ],
            'local_ends_at' => [
                'nullable',
                'date_format:Y-m-d\\TH:i',
                'required_if:window_type,absolute',
                'prohibited_if:window_type,weekly',
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_available' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function availabilityFieldNames(): array
    {
        return [
            'scope',
            'bookable_service_id',
            'scheduling_host_id',
            'window_type',
            'timezone',
            'weekday',
            'start_time',
            'end_time',
            'local_starts_at',
            'local_ends_at',
            'capacity',
            'is_available',
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
                'availability' => 'Unsupported availability fields were submitted.',
            ]);
        }
    }

    private function availabilityException(\Throwable $exception): ValidationException
    {
        return ValidationException::withMessages([
            'availability' => $exception->getMessage(),
        ]);
    }

    private function availabilityRedirect(string $event): RedirectResponse
    {
        $message = match ($event) {
            'created' => 'Availability rule created.',
            'updated' => 'Availability rule updated.',
            'archived' => 'Availability rule archived.',
            'restored' => 'Availability rule restored.',
            default => 'Availability configuration updated.',
        };

        return redirect()
            ->route('crm.scheduling.configuration.availability.index')
            ->with('success', $message);
    }
}