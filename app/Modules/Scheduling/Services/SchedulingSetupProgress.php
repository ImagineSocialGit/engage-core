<?php

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Scheduling\Models\BookableService;

final class SchedulingSetupProgress
{
    private ?int $activeUserCount = null;

    /** @var array{recommended: bool, label: string, description: string}|null */
    private ?array $staffGuidanceCache = null;

    public function __construct(
        private readonly UserAccessService $access,
        private readonly SchedulingServiceReadiness $readiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forService(
        ?BookableService $service,
        string $currentStep,
    ): array {
        $serviceReadiness = $service instanceof BookableService
            ? $this->readiness->forService($service)
            : null;
        $staffGuidance = $this->staffGuidance();
        $staffRequired = (bool) ($serviceReadiness['staff_required'] ?? false);

        $definitions = [
            [
                'key' => 'appointment_type',
                'number' => 1,
                'label' => 'Appointment details',
                'description' => 'Set the name, normal length, and how the appointment happens. These are the minimum details needed before booking setup can be considered complete.',
                'required' => true,
                'recommended' => false,
                'complete' => (bool) (($serviceReadiness['active'] ?? false)
                    && ($serviceReadiness['format_complete'] ?? false)),
                'url' => $service instanceof BookableService
                    ? route('crm.scheduling.configuration.services.details.edit', $service)
                    : route('crm.scheduling.configuration.services.index'),
            ],
            [
                'key' => 'availability',
                'number' => 2,
                'label' => 'Availability',
                'description' => 'Set regular hours, start-time spacing, one-off changes, and time protected around appointments.',
                'required' => true,
                'recommended' => false,
                'complete' => (bool) ($serviceReadiness['has_availability'] ?? false),
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
                'complete' => $staffRequired
                    ? (bool) ($serviceReadiness['has_active_staff'] ?? false)
                    : false,
                'url' => $service instanceof BookableService
                    ? route('crm.scheduling.configuration.services.staff.edit', $service)
                    : route('crm.scheduling.configuration.staff.index'),
            ],
            [
                'key' => 'ready',
                'number' => 4,
                'label' => 'Ready to test',
                'description' => 'Create a test or real appointment using the same availability and assignment rules the CRM will use day to day.',
                'required' => true,
                'recommended' => false,
                'complete' => (bool) ($serviceReadiness['internal_ready'] ?? false),
                'url' => $service instanceof BookableService
                    && (bool) ($serviceReadiness['internal_ready'] ?? false)
                        ? route('crm.scheduling.appointments.create', [
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

        $next = collect($steps)->first(
            fn (array $step): bool => ! $step['complete'] && $step['required'],
        );

        if (! is_array($next) && (bool) ($serviceReadiness['internal_ready'] ?? false)) {
            $next = collect($steps)->firstWhere('key', 'ready');
        }

        return [
            'service_id' => $service?->getKey(),
            'service_name' => $service?->name,
            'current_step' => $currentStep,
            'ready' => (bool) ($serviceReadiness['internal_ready'] ?? false),
            'public_ready' => (bool) ($serviceReadiness['public_ready'] ?? false),
            'has_availability' => (bool) ($serviceReadiness['has_availability'] ?? false),
            'has_active_staff' => (bool) ($serviceReadiness['has_active_staff'] ?? false),
            'staff_required' => $staffRequired,
            'staff_guidance' => $staffGuidance,
            'blockers' => $serviceReadiness['internal_blockers'] ?? [],
            'public_blockers' => $serviceReadiness['public_blockers'] ?? [],
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
        return $this->readiness->hasAvailability($service);
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
                'state_label' => 'Current',
            ];
        }

        if ($step['complete']) {
            return [
                'state' => 'complete',
                'state_label' => is_string($step['state_label_override'] ?? null)
                    ? $step['state_label_override']
                    : 'Complete',
            ];
        }

        if ($step['required']) {
            return [
                'state' => 'required',
                'state_label' => 'Needs attention',
            ];
        }

        return [
            'state' => $step['recommended'] ? 'recommended' : 'optional',
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