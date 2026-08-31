<?php

namespace App\Modules\Webinars\Automation;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class WebinarAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'webinars.registration_activity';

    private const EVENTS = [
        'webinar.registered' => 'Registers for a webinar',
        'webinar.cancelled' => 'Cancels a webinar registration',
        'webinar.attended' => 'Attends a webinar',
        'webinar.missed' => 'Misses a webinar',
    ];

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'webinars',
            name: 'Webinar activity occurs',
            description: 'Run after selected registration or attendance activity.',
            sortOrder: 70,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY;
    }

    public function fields(string $authoringKey): array
    {
        return [
            [
                'type' => 'select',
                'name' => 'webinar_event_key',
                'label' => 'Webinar activity',
                'required' => true,
                'placeholder' => 'Choose an activity',
                'options' => collect(self::EVENTS)->map(
                    fn (string $label, string $value): array => compact('value', 'label'),
                )->values()->all(),
            ],
            [
                'type' => 'select',
                'name' => 'webinar_series_slug',
                'label' => 'Webinar series',
                'required' => false,
                'placeholder' => 'Any webinar series',
                'options' => WebinarSeries::query()
                    ->orderBy('title')
                    ->orderBy('id')
                    ->get(['slug', 'title'])
                    ->map(fn (WebinarSeries $series): array => [
                        'value' => (string) $series->slug,
                        'label' => (string) $series->title,
                    ])
                    ->all(),
                'help' => 'Leave this open to run for the selected activity in any series.',
            ],
        ];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'webinar_event_key' => ['required', 'string', Rule::in(array_keys(self::EVENTS))],
            'webinar_series_slug' => [
                'nullable',
                'string',
                Rule::exists('webinar_series', 'slug'),
            ],
        ];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        $seriesSlug = trim((string) ($input['webinar_series_slug'] ?? ''));

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: trim((string) $input['webinar_event_key']),
            entryConditions: $seriesSlug === '' ? [] : [[
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.webinar_series.slug',
                'operator' => 'equals',
                'value' => $seriesSlug,
            ]],
        );
    }
}