<?php

namespace App\Actions\CRM\Tasks;

use App\Actions\Messaging\Internal\ScheduleInternalNotificationAction;
use App\Data\CRM\Tasks\TaskDigest;
use App\Models\Task;
use App\Models\TeamMemberNotificationPreference;
use Illuminate\Support\Str;

class SendTaskDigestNotificationsAction
{
    public function __construct(
        private readonly BuildTaskDigestsAction $buildTaskDigests,
        private readonly ScheduleInternalNotificationAction $scheduleInternalNotification,
    ) {}

    public function handle(string $frequency): int
    {
        $scheduled = 0;

        foreach ($this->buildTaskDigests->handle($frequency) as $digest) {
            if ($this->scheduleDigest($digest)) {
                $scheduled++;
            }
        }

        return $scheduled;
    }

    private function scheduleDigest(TaskDigest $digest): bool
    {
        if (! $digest->hasTasks()) {
            return false;
        }

        $this->scheduleInternalNotification->handle(
            recipient: $digest->recipient,
            scope: 'crm_tasks',
            messageType: $digest->frequency,
            content: $this->content($digest),
            context: null,
            dedupeKey: $this->dedupeKey($digest),
            meta: [
                'frequency' => $digest->frequency,
                'task_count' => $digest->taskCount(),
                'task_ids' => $digest->tasks->pluck('id')->values()->all(),
            ],
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(TaskDigest $digest): array
    {
        $count = $digest->taskCount();
        $label = $this->frequencyLabel($digest->frequency);

        return [
            'subject' => "{$label}: {$count} open ".Str::plural('task', $count),
            'headline' => $label,
            'preheader' => "You have {$count} open ".Str::plural('task', $count).'.',
            'body' => [
                "You have {$count} open ".Str::plural('task', $count).' in your CRM task list.',
                ...$this->taskLines($digest),
            ],
            'details' => [
                'Recipient' => $digest->recipient->name,
                'Digest' => $label,
                'Open Tasks' => (string) $count,
            ],
            'sms_message' => "{$label}: {$count} open ".Str::plural('task', $count).'. Check your CRM for details.',
            'meta' => [
                'frequency' => $digest->frequency,
                'task_count' => $count,
                'task_ids' => $digest->tasks->pluck('id')->values()->all(),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function taskLines(TaskDigest $digest): array
    {
        return $digest->tasks
            ->take(10)
            ->map(fn (Task $task): string => $this->taskLine($task))
            ->when(
                $digest->taskCount() > 10,
                fn ($lines) => $lines->push('And '.($digest->taskCount() - 10).' more.')
            )
            ->values()
            ->all();
    }

    private function taskLine(Task $task): string
    {
        $line = '• '.$task->title;

        if ($task->due_at) {
            $line .= ' — due '.$task->due_at
                ->timezone(config('app.timezone'))
                ->format('M j, g:i A');
        }

        return $line;
    }

    private function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            TeamMemberNotificationPreference::TYPE_DAILY_DIGEST => 'Daily Task Digest',
            TeamMemberNotificationPreference::TYPE_WEEKLY_DIGEST => 'Weekly Task Digest',
            default => Str::of($frequency)->replace('_', ' ')->title()->toString(),
        };
    }

    private function dedupeKey(TaskDigest $digest): string
    {
        return implode(':', [
            'internal_notification',
            'task_digest',
            $digest->frequency,
            $digest->recipient->source->getMorphClass(),
            $digest->recipient->source->getKey(),
            now()->timezone(config('app.timezone'))->toDateString(),
        ]);
    }
}