<?php

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;

final class SchedulingSetupProgress
{
    private ?int $activeUserCount = null;

    /** @var array{recommended: bool, label: string, description: string}|null */
    private ?array $staffGuidanceCache = null;

    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forService(
        ?BookableService $service,
        string $currentStep,
    ): array {
        $hasService = $service instanceof BookableService
            && ! $service->trashed()
            && $service->status === BookableService::STATUS_ACTIVE;
        $hasAvailability = $hasService && $this->hasAvailability($service);
        $hasAssignmentRows = $hasService && BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->exists();
        $hasActiveStaff = $hasService && BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->whereHas('schedulingHost', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('status', SchedulingHost::STATUS_ACTIVE))
            ->exists();
        $staffGuidance = $this->staffGuidance();
        $staffRequired = $hasAssignmentRows;
        $ready = $hasService
            && $hasAvailability
            && (! $staffRequired || $hasActiveStaff);

        $definitions = [
            [
                'key' => 'appointment_type',
                'number' => 1,
                'label' => 'Appointment type',
                'description' => 'Name what people can schedule and set its normal length.',
                'required' => true,
                'recommended' => false,
                'complete' => $hasService,
                'url' => $service instanceof BookableService
                    ? route('crm.scheduling.configuration.services.edit', $service)
                    : route('crm.scheduling.configuration.services.index'),
            ],
            [
                'key' => 'availability',
                'number' => 2,
                'label' => 'Availability',
                'description' => 'Set normal hours, one-off changes, start-time spacing, and time around appointments.',
                'required' => true,
                'recommended' => false,
                'complete' => $hasAvailability,
                'url' => $service instanceof BookableService
                    ? route('crm.scheduling.configuration.availability.index', [
                        'service_id' => $service->getKey(),
                    ])
                    : route('crm.scheduling.configuration.availability.index'),
            ],
            [
                'key' => 'staff',
                'number' => 3,
                'label' => 'Staff & providers',
                'description' => $staffGuidance['description'],
                'required' => $staffRequired,
                'recommended' => ! $staffRequired && $staffGuidance['recommended'],
                'complete' => $hasActiveStaff,
                'url' => $service instanceof BookableService
                    ? route('crm.scheduling.configuration.services.edit', $service).'#staff'
                    : route('crm.scheduling.configuration.staff.index'),
            ],
            [
                'key' => 'ready',
                'number' => 4,
                'label' => 'Ready to test',
                'description' => 'Check the actual times this appointment type can offer, then book a test or real appointment.',
                'required' => true,
                'recommended' => false,
                'complete' => $ready,
                'url' => $service instanceof BookableService && $ready
                    ? route('crm.scheduling.index', [
                        'bookable_service_id' => $service->getKey(),
                    ])
                    : null,
            ],
        ];

        $steps = array_map(
            fn (array $step): array => [
                ...$step,
                ...$this->state($step, $currentStep),
            ],
            $definitions,
        );

        $current = collect($steps)->firstWhere('key', $currentStep);
        $next = is_array($current) && $current['complete']
            ? collect($steps)->first(
                fn (array $step): bool =>
                    $step['key'] !== $currentStep
                    && ! $step['complete']
                    && ($step['required'] || $step['recommended']),
            )
            : null;

        if (! is_array($next) && $ready) {
            $next = collect($steps)->firstWhere('key', 'ready');
        }

        return [
            'service_id' => $service?->getKey(),
            'service_name' => $service?->name,
            'current_step' => $currentStep,
            'ready' => $ready,
            'has_availability' => $hasAvailability,
            'has_active_staff' => $hasActiveStaff,
            'staff_required' => $staffRequired,
            'staff_guidance' => $staffGuidance,
            'steps' => $steps,
            'next_action' => is_array($next) && filled($next['url'] ?? null)
                ? [
                    'label' => $next['key'] === 'ready'
                        ? 'Book a test appointment'
                        : 'Continue to '.$next['label'],
                    'url' => $next['url'],
                ]
                : null,
        ];
    }

    /**
     * @return array{recommended: bool, label: string, description: string}
     */
    public function staffGuidance(): array
    {
        if ($this->staffGuidanceCache !== null) {
            return $this->staffGuidanceCache;
        }

        $recommended = module_enabled('internal_notifications')
            || module_enabled('messaging')
            || module_enabled('tasks')
            || $this->activeUserCount() > 1;

        return $this->staffGuidanceCache = [
            'recommended' => $recommended,
            'label' => $recommended ? 'Recommended' : 'Optional',
            'description' => $recommended
                ? 'Assign staff when the right CRM user should own person-specific availability, appointment tasks, or enabled team notifications.'
                : 'Add staff only when appointments need to be assigned to a specific person or provider.',
        ];
    }

    public function hasAvailability(BookableService $service): bool
    {
        if (! $service->exists || $service->trashed()) {
            return false;
        }

        if (SchedulingAvailabilityWindow::query()
            ->where('is_available', true)
            ->where('bookable_service_id', $service->getKey())
            ->exists()
        ) {
            return true;
        }

        $hostIds = BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->pluck('scheduling_host_id');

        if ($hostIds->isEmpty()) {
            return false;
        }

        return SchedulingAvailabilityWindow::query()
            ->where('is_available', true)
            ->whereNull('bookable_service_id')
            ->whereIn('scheduling_host_id', $hostIds)
            ->exists();
    }

    /**
     * @param array<string, mixed> $step
     * @return array{state: string, state_label: string}
     */
    private function state(array $step, string $currentStep): array
    {
        if ($step['key'] === $currentStep) {
            return [
                'state' => 'current',
                'state_label' => 'Current step',
            ];
        }

        if ($step['complete']) {
            return [
                'state' => 'complete',
                'state_label' => 'Complete',
            ];
        }

        if ($step['required']) {
            return [
                'state' => 'required',
                'state_label' => 'Required',
            ];
        }

        return [
            'state' => 'recommended',
            'state_label' => $step['recommended'] ? 'Recommended' : 'Optional',
        ];
    }

    private function activeUserCount(): int
    {
        return $this->activeUserCount ??= User::query()
            ->get()
            ->filter(fn (User $user): bool => $this->access->isActive($user))
            ->count();
    }
}