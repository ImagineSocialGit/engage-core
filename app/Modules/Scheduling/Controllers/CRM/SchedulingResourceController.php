<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Services\SchedulingReadService;
use App\Modules\Scheduling\Services\SchedulingResourceConfigurationWriter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingResourceController extends Controller
{
    public function index(SchedulingReadService $read): View
    {
        return view('crm.scheduling.resources', [
            'title' => 'Scheduling Resources',
            'heading' => 'Scheduling Resources',
            'resources' => $read->configurationResources(),
            'hosts' => $read->configurationResourceHosts(),
            'services' => $read->configurationResourceServices(),
            'effects' => $read->resourceConfigurationEffects(),
        ]);
    }

    public function store(
        Request $request,
        SchedulingResourceConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'key',
            'name',
            'status',
            'sort_order',
        ]);
        $validated = $request->validate($this->resourceRules(includeKey: true));

        try {
            $writer->createResource($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->resourceException($exception);
        }

        return $this->resourceRedirect('resource-created');
    }

    public function update(
        Request $request,
        SchedulingResource $schedulingResource,
        SchedulingResourceConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            'name',
            'status',
            'sort_order',
        ]);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            ...$this->resourceRules(includeKey: false),
        ]);

        try {
            $writer->updateResource(
                resource: $schedulingResource,
                attributes: $validated,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->resourceException($exception);
        }

        return $this->resourceRedirect('resource-updated');
    }

    public function updateHostResources(
        Request $request,
        SchedulingHost $schedulingHost,
        SchedulingResourceConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            'resources',
        ]);
        $this->assertNestedFields(
            request: $request,
            field: 'resources',
            allowed: [
                'scheduling_resource_id',
                'is_active',
                'capacity',
                'sort_order',
            ],
        );
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            'resources' => ['nullable', 'array'],
            'resources.*' => ['array'],
            'resources.*.scheduling_resource_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('scheduling_resources', 'id'),
            ],
            'resources.*.is_active' => ['required', 'boolean'],
            'resources.*.capacity' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'resources.*.sort_order' => [
                'required',
                'integer',
                'min:0',
                'max:100000',
            ],
        ]);

        try {
            $writer->syncHostResources(
                host: $schedulingHost,
                resources: $validated['resources'] ?? [],
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->resourceException($exception);
        }

        return $this->resourceRedirect('host-resources-updated');
    }

    public function updateServiceRequirements(
        Request $request,
        BookableService $bookableService,
        SchedulingResourceConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            'resources',
        ]);
        $this->assertNestedFields(
            request: $request,
            field: 'resources',
            allowed: [
                'scheduling_resource_id',
                'is_active',
                'quantity',
                'sort_order',
            ],
        );
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            'resources' => ['nullable', 'array'],
            'resources.*' => ['array'],
            'resources.*.scheduling_resource_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('scheduling_resources', 'id'),
            ],
            'resources.*.is_active' => ['required', 'boolean'],
            'resources.*.quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'resources.*.sort_order' => [
                'required',
                'integer',
                'min:0',
                'max:100000',
            ],
        ]);

        try {
            $writer->syncServiceRequirements(
                service: $bookableService,
                resources: $validated['resources'] ?? [],
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->resourceException($exception);
        }

        return $this->resourceRedirect('service-requirements-updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceRules(bool $includeKey): array
    {
        return array_filter([
            'key' => $includeKey
                ? [
                    'required',
                    'string',
                    'max:191',
                    'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                    Rule::unique('scheduling_resources', 'key'),
                ]
                : null,
            'name' => ['required', 'string', 'max:255'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    SchedulingResource::STATUS_ACTIVE,
                    SchedulingResource::STATUS_INACTIVE,
                    SchedulingResource::STATUS_ARCHIVED,
                ]),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], static fn (mixed $rules): bool => is_array($rules));
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
                'resources' => 'Unsupported resource configuration fields were submitted.',
            ]);
        }
    }

    /**
     * @param array<int, string> $allowed
     */
    private function assertNestedFields(
        Request $request,
        string $field,
        array $allowed,
    ): void {
        $rows = $request->input($field, []);

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $unexpected = array_values(array_diff(
                array_keys($row),
                $allowed,
            ));

            if ($unexpected !== []) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}" => 'Unsupported resource row fields were submitted.',
                ]);
            }
        }
    }

    private function resourceException(\Throwable $exception): ValidationException
    {
        return ValidationException::withMessages([
            'resources' => $exception->getMessage(),
        ]);
    }

    private function resourceRedirect(string $event): RedirectResponse
    {
        $message = match ($event) {
            'resource-created' => 'Scheduling resource created.',
            'resource-updated' => 'Scheduling resource updated.',
            'host-resources-updated' => 'Host resource capacities updated.',
            'service-requirements-updated' => 'Service resource requirements updated.',
            default => 'Scheduling resource configuration updated.',
        };

        return redirect()
            ->route('crm.scheduling.configuration.resources.index')
            ->with('success', $message);
    }
}