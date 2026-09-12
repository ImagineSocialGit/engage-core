<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Core\Access\Models\Team;

class TaskTemplatePresentationResolver
{
    /** @return array<string, mixed> */
    public function present(TaskTemplate $template): array
    {
        $template->loadMissing(['assignedTo', 'responsible']);

        return [
            'id' => (int) $template->getKey(),
            'key' => (string) $template->key,
            'name' => (string) $template->name,
            'title' => (string) $template->title,
            'description' => (string) ($template->description ?? ''),
            'task_description' => (string) ($template->task_description ?? ''),
            'priority' => $template->priority ? Str::headline((string) $template->priority) : 'Normal',
            'due' => $this->dueLabel($template),
            'assignment' => $this->assignmentLabel($template),
            'responsible_party' => match ($template->responsible_party) {
                Task::RESPONSIBLE_PARTY_CONTACT => config('contacts.labels.singular', 'Contact'),
                Task::RESPONSIBLE_PARTY_THIRD_PARTY => 'Third party',
                Task::RESPONSIBLE_PARTY_UNKNOWN => 'Not specified',
                default => 'Internal team',
            },
            'is_active' => (bool) $template->is_active,
            'is_customized' => (bool) $template->is_customized,
            'edit_url' => route('crm.tasks.templates.edit', $template),
        ];
    }

    private function assignmentLabel(TaskTemplate $template): string
    {
        if ($template->assignedTo instanceof Model) {
            $label = $template->assignedTo->getAttribute('name')
                ?? $template->assignedTo->getAttribute('email');

            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        $strategy = trim((string) ($template->assigned_to_strategy ?? ''));

        if (str_starts_with($strategy, CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX)) {
            $teamId = (int) substr($strategy, strlen(CoreTeamRoundRobinTaskAssignmentStrategyResolver::PREFIX));
            $team = Team::query()->find($teamId);

            return $team ? 'Round-robin: '.$team->name : 'Round-robin Team unavailable';
        }

        return $strategy === '' || $strategy === TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED
            ? 'Unassigned'
            : Str::headline($strategy);
    }

    private function dueLabel(TaskTemplate $template): string
    {
        $timing = data_get($template->meta, 'timing');

        if (is_array($timing)) {
            if (($timing['mode'] ?? null) === 'none') {
                return 'No automatic due date';
            }

            if (($timing['mode'] ?? null) === 'immediately') {
                return 'Immediately';
            }

            if (($timing['mode'] ?? null) === 'after'
                && is_numeric($timing['value'] ?? null)
                && in_array($timing['unit'] ?? null, TaskTemplateTimingResolver::UNITS, true)
            ) {
                $value = (int) $timing['value'];
                $unit = match ($timing['unit']) {
                    'business_days' => 'business day',
                    default => rtrim(str_replace('_', ' ', $timing['unit']), 's'),
                };

                return $value.' '.Str::plural($unit, $value).' after creation';
            }
        }

        $minutes = $template->due_offset_minutes;

        if ($minutes === null) {
            return 'No automatic due date';
        }

        if ($minutes === 0) {
            return 'Immediately';
        }

        if ($minutes % 10080 === 0) {
            $weeks = intdiv($minutes, 10080);

            return $weeks.' '.Str::plural('week', $weeks).' after creation';
        }

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.' '.Str::plural('day', $days).' after creation';
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.Str::plural('hour', $hours).' after creation';
        }

        return $minutes.' '.Str::plural('minute', $minutes).' after creation';
    }
}