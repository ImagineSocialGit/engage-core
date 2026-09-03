<?php

namespace App\Modules\FlowRoutes\Authoring;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\ChangeStatusPointDefinition;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use App\Support\ReplyHandling\ReplyProfilePresentationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FlowRoutesAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function __construct(
        private readonly ReplyProfilePresentationRegistry $replyProfiles,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'branch_evaluate',
            moduleKey: 'flow_routes',
            name: 'Decision',
            description: 'Check one business fact and direct the Route to a later Point when it matches.',
            tip: 'Use a Decision only when the next action depends on a contact fact or reply outcome. Each path must move forward so the Route cannot loop back on itself.',
            useCases: [
                'Continue to a task only when the contact has a selected status.',
                'Handle a configured reply outcome differently from other replies.',
            ],
            typeLabel: 'Decision',
            genericLabels: ['branch evaluate', 'evaluate branch', 'decision'],
            nameFieldLabel: 'Decision question',
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: 'wait',
            moduleKey: 'flow_routes',
            name: 'Wait',
            description: 'Pause this Route for a period of time or until a specific date and time. Wait can never be the final Point.',
            tip: 'Use a Wait when the next step should happen later, not immediately. When added, it is placed before the current final Point so something always happens afterward.',
            useCases: [
                'Wait 7 days before checking whether follow-up is still needed.',
                'Wait 2 business days before the first follow-up action.',
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
        return ! in_array($pointType, ['wait', 'branch_evaluate'], true)
            || $context->existingPointTypes !== [];
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        return match ($pointType) {
            'wait' => $this->waitFields($definition),
            'branch_evaluate' => $this->decisionFields($definition, $context),
            'change_status' => $this->changeStatusFields($definition),
            default => [],
        };
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return match ($pointType) {
            'wait' => [
                'wait_mode' => ['nullable', 'in:duration,resume_at'],
                'duration_value' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'duration_unit' => ['nullable', 'in:minutes,hours,days,business_days,weeks'],
                'resume_at' => ['nullable', 'date'],
            ],
            'branch_evaluate' => [
                'preserve_definition' => ['nullable', 'boolean'],
                'decision_fact' => ['required_unless:preserve_definition,1', 'nullable', 'in:contact_status,contact_tag,reply_outcome'],
                'decision_status_key' => ['required_if:decision_fact,contact_status', 'nullable', 'string', 'max:255'],
                'decision_tag' => ['required_if:decision_fact,contact_tag', 'nullable', 'string', 'max:255'],
                'decision_reply_outcome' => ['required_if:decision_fact,reply_outcome', 'nullable', 'string', 'max:255'],
                'decision_target_point_key' => ['required_unless:preserve_definition,1', 'nullable', 'string', 'max:255'],
                'decision_otherwise_target_point_key' => ['nullable', 'string', 'max:255'],
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
            'branch_evaluate' => $this->decisionDefinition($input, $context),
            'change_status' => $this->changeStatusDefinition($input, $context),
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
            'branch_evaluate' => 'Decision',
            'change_status' => 'Change status to '.Str::headline((string) ($definition['contact_status_key'] ?? 'selected status')),
            default => $fallback,
        };
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return match ($pointType) {
            'wait' => $this->waitSummary($definition),
            'branch_evaluate' => $this->decisionSummary($definition),
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
    private function decisionFields(array $definition, AutomationPointAuthoringContext $context): array
    {
        $draft = $this->decisionDraft($definition);

        if ($definition !== [] && $draft === null) {
            return [
                [
                    'type' => 'notice',
                    'title' => 'This Decision has advanced paths',
                    'body' => 'Its existing paths will be preserved. Use the Decision summary to review what happens, or replace it with simpler forward-only Decisions.',
                ],
                [
                    'type' => 'hidden',
                    'name' => 'preserve_definition',
                    'value' => '1',
                ],
            ];
        }

        $draft ??= [
            'fact' => 'contact_status',
            'status_key' => '',
            'tag' => '',
            'reply_outcome' => '',
            'target' => '',
            'otherwise_target' => '',
        ];

        $targetOptions = $this->decisionTargetOptions($context);

        return [
            [
                'type' => 'select',
                'name' => 'decision_fact',
                'label' => 'What should this Decision check?',
                'required' => true,
                'state' => true,
                'value' => $draft['fact'],
                'options' => [
                    ['value' => 'contact_status', 'label' => 'Contact status'],
                    ['value' => 'contact_tag', 'label' => 'Contact tag'],
                    ['value' => 'reply_outcome', 'label' => 'Reply outcome'],
                ],
            ],
            [
                'type' => 'select',
                'name' => 'decision_status_key',
                'label' => 'Status to match',
                'required' => true,
                'value' => $draft['status_key'],
                'placeholder' => 'Choose a status',
                'show_when' => ['field' => 'decision_fact', 'equals' => 'contact_status'],
                'options' => ContactStatus::query()
                    ->active()
                    ->ordered()
                    ->get(['key', 'name'])
                    ->map(fn (ContactStatus $status): array => [
                        'value' => (string) $status->key,
                        'label' => (string) $status->name,
                    ])->all(),
            ],
            [
                'type' => 'text',
                'name' => 'decision_tag',
                'label' => 'Tag to match',
                'required' => true,
                'value' => $draft['tag'],
                'show_when' => ['field' => 'decision_fact', 'equals' => 'contact_tag'],
            ],
            [
                'type' => 'select',
                'name' => 'decision_reply_outcome',
                'label' => 'Reply outcome to match',
                'required' => true,
                'value' => $draft['reply_outcome'],
                'placeholder' => 'Choose a reply outcome',
                'show_when' => ['field' => 'decision_fact', 'equals' => 'reply_outcome'],
                'options' => $this->replyOutcomeOptions(),
            ],
            [
                'type' => 'select',
                'name' => 'decision_target_point_key',
                'label' => 'When it matches, continue at',
                'required' => true,
                'value' => $draft['target'],
                'placeholder' => 'Choose a later Point',
                'options' => $targetOptions,
            ],
            [
                'type' => 'select',
                'name' => 'decision_otherwise_target_point_key',
                'label' => 'Otherwise',
                'value' => $draft['otherwise_target'],
                'placeholder' => 'End this Route',
                'options' => $targetOptions,
                'help' => 'Leave blank to end the Route when the fact does not match.',
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    private function decisionDefinition(array $input, AutomationPointAuthoringContext $context): array
    {
        if (filter_var($input['preserve_definition'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if ($context->point instanceof FlowRoutePoint && is_array($context->point->definition)) {
                return $context->point->definition;
            }

            throw ValidationException::withMessages([
                'preserve_definition' => 'There is no existing Decision definition to preserve.',
            ]);
        }

        $fact = trim((string) ($input['decision_fact'] ?? ''));
        $target = trim((string) ($input['decision_target_point_key'] ?? ''));
        $otherwiseTarget = trim((string) ($input['decision_otherwise_target_point_key'] ?? ''));
        $allowedTargets = collect($this->decisionTargetOptions($context))->pluck('value')->all();

        if ($target === '' || ! in_array($target, $allowedTargets, true)) {
            throw ValidationException::withMessages([
                'decision_target_point_key' => 'Choose an active Point in this Route.',
            ]);
        }

        if ($otherwiseTarget !== '' && ! in_array($otherwiseTarget, $allowedTargets, true)) {
            throw ValidationException::withMessages([
                'decision_otherwise_target_point_key' => 'Choose an active Point in this Route or end the Route.',
            ]);
        }

        $conditions = match ($fact) {
            'contact_status' => [$this->statusDecisionCondition($input)],
            'contact_tag' => [$this->tagDecisionCondition($input)],
            'reply_outcome' => $this->replyDecisionConditions($input),
            default => throw ValidationException::withMessages([
                'decision_fact' => 'Choose what this Decision should check.',
            ]),
        };

        $definition = [
            'branches' => [[
                'conditions' => $conditions,
                'target_flow_route_point_key' => $target,
            ]],
            'on_no_match' => BranchEvaluatePointDefinition::ON_NO_MATCH_COMPLETED,
        ];

        if ($otherwiseTarget !== '') {
            $definition['default_target_flow_route_point_key'] = $otherwiseTarget;
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    private function statusDecisionCondition(array $input): array
    {
        $statusKey = trim((string) ($input['decision_status_key'] ?? ''));

        if (! ContactStatus::query()->active()->where('key', $statusKey)->exists()) {
            throw ValidationException::withMessages([
                'decision_status_key' => 'Choose an active status.',
            ]);
        }

        return [
            'source' => 'contact_status',
            'path' => 'key',
            'operator' => 'equals',
            'value' => $statusKey,
        ];
    }

    /** @return array<string, mixed> */
    private function tagDecisionCondition(array $input): array
    {
        $tag = trim((string) ($input['decision_tag'] ?? ''));

        if ($tag === '') {
            throw ValidationException::withMessages([
                'decision_tag' => 'Enter the tag this Decision should match.',
            ]);
        }

        return [
            'source' => 'contact_tags',
            'path' => 'values',
            'operator' => 'contains',
            'value' => $tag,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function replyDecisionConditions(array $input): array
    {
        $outcome = trim((string) ($input['decision_reply_outcome'] ?? ''));
        [$profileKey, $intentKey] = array_pad(explode('::', $outcome, 2), 2, '');
        $profile = $this->replyProfiles->find($profileKey);
        $intentExists = $profile !== null && collect($profile->intents)->contains(
            fn (array $intent): bool => (string) ($intent['key'] ?? '') === $intentKey
                && (bool) ($intent['active'] ?? false),
        );

        if (! $profile?->active || ! $intentExists) {
            throw ValidationException::withMessages([
                'decision_reply_outcome' => 'Choose an active reply outcome.',
            ]);
        }

        return [
            [
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                'operator' => 'equals',
                'value' => $profileKey,
            ],
            [
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                'operator' => 'equals',
                'value' => $intentKey,
            ],
        ];
    }

    /** @return array<int, array{value: string, label: string}> */
    private function decisionTargetOptions(AutomationPointAuthoringContext $context): array
    {
        if (! $context->container instanceof FlowRoute) {
            return [];
        }

        $points = $context->container->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->get(['id', 'key', 'name', 'sort_order']);

        if ($context->point instanceof FlowRoutePoint) {
            $points = $points->filter(
                fn (FlowRoutePoint $point): bool => (int) $point->sort_order > (int) $context->point->sort_order,
            );
        }

        return $points->map(fn (FlowRoutePoint $point): array => [
            'value' => (string) $point->key,
            'label' => (string) ($point->name ?: Str::headline((string) $point->key)),
        ])->values()->all();
    }

    /** @return array<int, array{value: string, label: string}> */
    private function replyOutcomeOptions(): array
    {
        return collect($this->replyProfiles->all())
            ->filter(fn ($profile): bool => $profile->active)
            ->flatMap(fn ($profile): array => collect($profile->intents)
                ->filter(fn (array $intent): bool => (bool) ($intent['active'] ?? false))
                ->map(fn (array $intent): array => [
                    'value' => $profile->key.'::'.(string) $intent['key'],
                    'label' => $profile->label.' — '.(string) $intent['label'],
                ])->all())
            ->values()
            ->all();
    }

    /** @return array<string, string>|null */
    private function decisionDraft(array $definition): ?array
    {
        if ($definition === []) {
            return null;
        }

        $parsed = BranchEvaluatePointDefinition::from($definition);

        if (! $parsed->isValid() || count($parsed->branches) !== 1) {
            return null;
        }

        $branch = $parsed->branches[0];
        $conditions = is_array($branch['conditions'] ?? null)
            ? array_values(array_filter($branch['conditions'], 'is_array'))
            : [];
        $target = trim((string) ($branch['target_flow_route_point_key'] ?? ''));
        $otherwise = (string) ($parsed->defaultTargetFlowRoutePointKey ?? '');

        if (count($conditions) === 1) {
            $condition = $conditions[0];
            $operator = (string) ($condition['operator'] ?? '');
            $value = is_string($condition['value'] ?? null) ? trim($condition['value']) : '';

            if (($condition['source'] ?? null) === 'contact_status'
                && ($condition['path'] ?? null) === 'key'
                && $operator === 'equals'
                && $value !== '') {
                return compact('target') + [
                    'fact' => 'contact_status',
                    'status_key' => $value,
                    'tag' => '',
                    'reply_outcome' => '',
                    'otherwise_target' => $otherwise,
                ];
            }

            if (($condition['source'] ?? null) === 'contact_tags'
                && $operator === 'contains'
                && $value !== '') {
                return compact('target') + [
                    'fact' => 'contact_tag',
                    'status_key' => '',
                    'tag' => $value,
                    'reply_outcome' => '',
                    'otherwise_target' => $otherwise,
                ];
            }
        }

        if (count($conditions) === 2) {
            $valuesByPath = collect($conditions)->mapWithKeys(function (array $condition): array {
                if (($condition['source'] ?? null) !== 'execution_meta'
                    || ($condition['operator'] ?? null) !== 'equals'
                    || ! is_string($condition['path'] ?? null)
                    || ! is_string($condition['value'] ?? null)) {
                    return [];
                }

                return [(string) $condition['path'] => (string) $condition['value']];
            });
            $profileKey = $valuesByPath->get('automation_event.payload.inbound_message.reply_profile_key');
            $intentKey = $valuesByPath->get('automation_event.payload.inbound_message.reply_intent_key');

            if (is_string($profileKey) && $profileKey !== '' && is_string($intentKey) && $intentKey !== '') {
                return compact('target') + [
                    'fact' => 'reply_outcome',
                    'status_key' => '',
                    'tag' => '',
                    'reply_outcome' => $profileKey.'::'.$intentKey,
                    'otherwise_target' => $otherwise,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $definition */
    private function decisionSummary(array $definition): string
    {
        $parsed = BranchEvaluatePointDefinition::from($definition);

        return $parsed->isValid()
            ? 'Choose what happens next using '.count($parsed->branches).' configured '.Str::plural('path', count($parsed->branches)).'.'
            : 'Review this Decision before the Route continues.';
    }

    /** @param array<string, mixed> $definition */
    private function waitFields(array $definition): array
    {
        $resumeAt = (string) ($definition['resume_at'] ?? '');
        $mode = $resumeAt !== '' ? 'resume_at' : 'duration';
        $unit = 'days';
        $value = 1;

        foreach (['weeks', 'business_days', 'days', 'hours', 'minutes'] as $candidate) {
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
                'state' => true,
                'options' => [
                    ['value' => 'minutes', 'label' => 'Minutes'],
                    ['value' => 'hours', 'label' => 'Hours'],
                    ['value' => 'days', 'label' => 'Days'],
                    ['value' => 'business_days', 'label' => 'Business days'],
                    ['value' => 'weeks', 'label' => 'Weeks'],
                ],
                'show_when' => ['field' => 'wait_mode', 'equals' => 'duration'],
            ],
            [
                'type' => 'resource',
                'title' => 'How business days are counted',
                'body' => 'Business-day waits skip the weekdays and dates selected for this business. Changing that calendar affects waits that begin later, not people who are already waiting.',
                'action_url' => route('crm.business-calendar.edit', ['from' => 'routes']),
                'action_label' => 'Manage business days',
                'show_when' => ['field' => 'duration_unit', 'equals' => 'business_days'],
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

    /** @param array<string, mixed> $definition */
    private function changeStatusFields(array $definition): array
    {
        $parsed = ChangeStatusPointDefinition::from($definition);
        $fields = [[
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
        ]];

        if ($parsed->fromContactStatusKeys !== []) {
            $fields[] = [
                'type' => 'notice',
                'title' => 'Current-status safety check',
                'body' => 'This action runs only while the contact is still '.$this->statusList($parsed->fromContactStatusKeys).'. If the contact has already moved elsewhere, the Route safely skips this action.',
            ];
        }

        return $fields;
    }

    /** @param array<string, mixed> $input */
    private function changeStatusDefinition(
        array $input,
        AutomationPointAuthoringContext $context,
    ): array
    {
        $statusKey = trim((string) ($input['contact_status_key'] ?? ''));
        $status = ContactStatus::query()->active()->where('key', $statusKey)->first();

        if (! $status instanceof ContactStatus) {
            throw ValidationException::withMessages([
                'contact_status_key' => 'Choose an active status.',
            ]);
        }

        $existingDefinition = $context->point instanceof FlowRoutePoint
            && is_array($context->point->definition)
                ? $context->point->definition
                : [];
        $existingSettings = $context->point instanceof FlowRoutePoint
            && is_array($context->point->settings)
                ? $context->point->settings
                : [];
        $existing = ChangeStatusPointDefinition::from(
            definition: $existingDefinition,
            settings: $existingSettings,
        );

        return array_filter([
            'contact_status_key' => (string) $status->key,
            'from_contact_status_keys' => $existing->fromContactStatusKeys !== []
                ? $existing->fromContactStatusKeys
                : null,
            'reason' => $existing->reason ?? 'flow_route_change_status',
            'force' => $existing->force ?: null,
            'on_same_status' => $existing->onSameStatus ?? 'skipped',
            'meta' => $existing->meta !== [] ? $existing->meta : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $definition */
    private function waitSummary(array $definition): string
    {
        foreach (['weeks', 'business_days', 'days', 'hours', 'minutes', 'seconds'] as $unit) {
            $value = $definition[$unit] ?? null;

            if (is_numeric($value)) {
                if ($unit === 'business_days') {
                    return 'Wait '.$this->quantity((int) $value, 'business day').'.';
                }

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
        $parsed = ChangeStatusPointDefinition::from($definition);

        if ($parsed->fromContactStatusKeys !== []) {
            return 'Move the '.config('contacts.labels.singular', 'contact').' to '.$label
                .' only if its current status is '.$this->statusList($parsed->fromContactStatusKeys).'.';
        }

        return 'Move the '.config('contacts.labels.singular', 'contact').' to '.$label.'.';
    }

    /** @param array<int, string> $statusKeys */
    private function statusList(array $statusKeys): string
    {
        $namesByKey = ContactStatus::query()
            ->whereIn('key', $statusKeys)
            ->pluck('name', 'key')
            ->all();
        $labels = array_map(
            static fn (string $key): string => is_string($namesByKey[$key] ?? null)
                && trim($namesByKey[$key]) !== ''
                    ? trim($namesByKey[$key])
                    : Str::headline($key),
            $statusKeys,
        );

        if (count($labels) < 2) {
            return $labels[0] ?? 'an allowed status';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).(count($labels) > 1 ? ', or ' : ' or ').$last;
    }

    private function quantity(int $value, string $unit): string
    {
        return $value.' '.Str::plural($unit, $value);
    }
}