<?php

namespace App\Modules\FlowRoutes\Authoring;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FlowRoutesAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'wait',
            moduleKey: 'flow_routes',
            name: 'Wait',
            description: 'Pause this Route for a period of time or until a specific date and time. Wait can never be the final Point.',
            tip: 'Use a Wait when the next step should happen later, not immediately. When added, it is placed before the current final Point so something always happens afterward.',
            useCases: [
                'Wait 7 days before checking whether follow-up is still needed.',
                'Pause until a specific scheduled date.',
            ],
            typeLabel: 'Wait',
            genericLabels: ['wait'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: 'change_status',
            moduleKey: 'workflow',
            name: 'Change contact status',
            description: 'Move the contact to another status. Change Status always ends the Route.',
            tip: 'Use status changes for meaningful workflow movement. Change Status stays last because it hands the contact off to what comes next.',
            useCases: [
                'Move a webinar attendee to Attended Webinar.',
                'Move a qualified lead into a new status.',
            ],
            typeLabel: 'Status',
            genericLabels: ['change contact status'],
            generatedPrefixes: ['change status to '],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $pointType !== 'wait' || $context->existingPointTypes !== [];
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        return match ($pointType) {
            'wait' => $this->waitFields($definition),
            'change_status' => [[
                'type' => 'select',
                'name' => 'contact_status_key',
                'label' => 'New status',
                'required' => true,
                'value' => (string) ($definition['contact_status_key'] ?? ''),
                'placeholder' => 'Choose a status',
                'options' => ContactStatus::query()
                    ->active()
                    ->ordered()
                    ->get(['key', 'name'])
                    ->map(fn (ContactStatus $status): array => [
                        'value' => (string) $status->key,
                        'label' => (string) $status->name,
                    ])->all(),
            ]],
            default => [],
        };
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return match ($pointType) {
            'wait' => [
                'wait_mode' => ['nullable', 'in:duration,resume_at'],
                'duration_value' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'duration_unit' => ['nullable', 'in:minutes,hours,days,weeks'],
                'resume_at' => ['nullable', 'date'],
            ],
            'change_status' => [
                'contact_status_key' => ['required', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        return match ($pointType) {
            'wait' => $this->waitDefinition($input),
            'change_status' => $this->changeStatusDefinition($input),
            default => throw ValidationException::withMessages([
                'capability_id' => 'That FlowRoutes-native Point type is not authorable.',
            ]),
        };
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        $customName = trim((string) ($input['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        return match ($pointType) {
            'wait' => 'Wait',
            'change_status' => 'Change status to '.Str::headline((string) ($definition['contact_status_key'] ?? 'selected status')),
            default => $fallback,
        };
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return match ($pointType) {
            'wait' => $this->waitSummary($definition),
            'change_status' => $this->changeStatusSummary($definition),
            default => '',
        };
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return Str::of($this->summary($pointType, $definition, $context))
            ->rtrim('.')
            ->toString();
    }

    /** @param array<string, mixed> $definition */
    private function waitFields(array $definition): array
    {
        $resumeAt = (string) ($definition['resume_at'] ?? '');
        $mode = $resumeAt !== '' ? 'resume_at' : 'duration';
        $unit = 'days';
        $value = 1;

        foreach (['weeks', 'days', 'hours', 'minutes'] as $candidate) {
            if (is_numeric($definition[$candidate] ?? null)) {
                $unit = $candidate;
                $value = (int) $definition[$candidate];
                break;
            }
        }

        return [
            [
                'type' => 'select',
                'name' => 'wait_mode',
                'label' => 'Wait type',
                'value' => $mode,
                'state' => true,
                'options' => [
                    ['value' => 'duration', 'label' => 'For a duration'],
                    ['value' => 'resume_at', 'label' => 'Until a date and time'],
                ],
            ],
            [
                'type' => 'number',
                'name' => 'duration_value',
                'label' => 'Duration',
                'value' => $value,
                'min' => 0,
                'max' => 100000,
                'show_when' => ['field' => 'wait_mode', 'equals' => 'duration'],
            ],
            [
                'type' => 'select',
                'name' => 'duration_unit',
                'label' => 'Unit',
                'value' => $unit,
                'options' => [
                    ['value' => 'minutes', 'label' => 'Minutes'],
                    ['value' => 'hours', 'label' => 'Hours'],
                    ['value' => 'days', 'label' => 'Days'],
                    ['value' => 'weeks', 'label' => 'Weeks'],
                ],
                'show_when' => ['field' => 'wait_mode', 'equals' => 'duration'],
            ],
            [
                'type' => 'datetime-local',
                'name' => 'resume_at',
                'label' => 'Resume at',
                'value' => $resumeAt,
                'show_when' => ['field' => 'wait_mode', 'equals' => 'resume_at'],
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    private function waitDefinition(array $input): array
    {
        $mode = (string) ($input['wait_mode'] ?? 'duration');
        $definition = $mode === 'resume_at'
            ? ['resume_at' => $input['resume_at'] ?? null]
            : [(string) ($input['duration_unit'] ?? 'days') => $input['duration_value'] ?? null];

        $parsed = WaitPointDefinition::from($definition);

        if (! $parsed->isValid()) {
            throw ValidationException::withMessages([
                'wait_mode' => 'Choose a valid duration or a valid date and time.',
            ]);
        }

        return array_filter(
            $definition,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /** @param array<string, mixed> $input */
    private function changeStatusDefinition(array $input): array
    {
        $statusKey = trim((string) ($input['contact_status_key'] ?? ''));
        $status = ContactStatus::query()->active()->where('key', $statusKey)->first();

        if (! $status instanceof ContactStatus) {
            throw ValidationException::withMessages([
                'contact_status_key' => 'Choose an active status.',
            ]);
        }

        return [
            'contact_status_key' => (string) $status->key,
            'reason' => 'flow_route_change_status',
            'on_same_status' => 'skipped',
        ];
    }

    /** @param array<string, mixed> $definition */
    private function waitSummary(array $definition): string
    {
        foreach (['weeks', 'days', 'hours', 'minutes', 'seconds'] as $unit) {
            $value = $definition[$unit] ?? null;

            if (is_numeric($value)) {
                return 'Wait '.$this->quantity((int) $value, rtrim($unit, 's')).'.';
            }
        }

        $resumeAt = $definition['resume_at'] ?? null;

        if (is_string($resumeAt) && trim($resumeAt) !== '') {
            try {
                return 'Wait until '.CarbonImmutable::parse($resumeAt)->format('M j, Y \\a\\t g:i A').'.';
            } catch (Throwable) {
                return 'Wait until the scheduled time.';
            }
        }

        return 'Wait before continuing.';
    }

    /** @param array<string, mixed> $definition */
    private function changeStatusSummary(array $definition): string
    {
        $statusKey = (string) ($definition['contact_status_key'] ?? '');

        if ($statusKey === '') {
            return 'Update the status.';
        }

        $name = ContactStatus::query()->where('key', $statusKey)->value('name');
        $label = is_string($name) && trim($name) !== '' ? $name : Str::headline($statusKey);

        return 'Move the '.config('contacts.labels.singular', 'contact').' to '.$label.'.';
    }

    private function quantity(int $value, string $unit): string
    {
        return $value.' '.Str::plural($unit, $value);
    }
}
