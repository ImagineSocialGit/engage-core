<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskDigest;
use App\Modules\Tasks\Data\TaskNotification;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskNotificationScheduler;
use Illuminate\Support\Str;

class SendTaskDigestNotificationsAction
{
    public function __construct(
        private readonly BuildTaskDigestsAction $buildTaskDigests,
        private readonly TaskNotificationScheduler $notificationScheduler,
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

        return $this->notificationScheduler->schedule(new TaskNotification(
            recipient: $digest->recipient,
            notificationType: $digest->frequency,
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
        ));
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
                "You have {$count} open ".Str::plural('task', $count).' in your task list.',
                ...$this->taskLines($digest),
            ],
            'details' => [
                'Recipient' => $digest->recipient->name,
                'Digest' => $label,
                'Open Tasks' => (string) $count,
            ],
            'sms_message' => "{$label}: {$count} open ".Str::plural('task', $count).'. Check your task list for details.',
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
                fn ($lines) => $lines->push(
                    'And '.($digest->taskCount() - 10).' more.'
                ),
            )
            ->values()
            ->all();
    }

    private function taskLine(Task $task): string
    {
        $line = '• '.$task->title;

        if ($task->due_at) {
            $line .= ' — due '.$task->due_at
                ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
                ->format('M j, g:i A');
        }

        return $line;
    }

    private function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            BuildTaskDigestsAction::FREQUENCY_DAILY => 'Daily Task Digest',
            BuildTaskDigestsAction::FREQUENCY_WEEKLY => 'Weekly Task Digest',
            default => Str::of($frequency)->replace('_', ' ')->title()->toString(),
        };
    }

    private function dedupeKey(TaskDigest $digest): string
    {
        return implode(':', [
            'task_notification',
            'task_digest',
            $digest->frequency,
            $digest->recipient->source->getMorphClass(),
            $digest->recipient->source->getKey(),
            now()->timezone(config('app.timezone'))->toDateString(),
        ]);
    }
}
