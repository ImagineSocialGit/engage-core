<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskDigest;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BuildTaskDigestsAction
{
    public const FREQUENCY_DAILY = 'daily_digest';
    public const FREQUENCY_WEEKLY = 'weekly_digest';

    public function __construct(
        private readonly TaskAssignedRecipientsResolver $assignedRecipientsResolver,
    ) {}

    /**
     * @return Collection<int, TaskDigest>
     */
    public function handle(
        string $frequency,
        ?CarbonInterface $now = null,
    ): Collection {
        $now ??= now();

        $tasks = $this->tasksForFrequency($frequency, $now);

        $digests = [];

        foreach ($tasks as $task) {
            $recipients = $this->assignedRecipientsResolver->resolve($task);

            foreach ($recipients as $recipient) {
                $key = $this->recipientKey($recipient);

                $digests[$key] ??= [
                    'recipient' => $recipient,
                    'tasks' => collect(),
                ];

                $digests[$key]['tasks']->push($task);
            }
        }

        return collect($digests)
            ->map(fn (array $digest): TaskDigest => new TaskDigest(
                recipient: $digest['recipient'],
                tasks: $digest['tasks']->values(),
                frequency: $frequency,
            ))
            ->values();
    }

    /**
     * @return Collection<int, Task>
     */
    private function tasksForFrequency(
        string $frequency,
        CarbonInterface $now,
    ): Collection {
        return Task::query()
            ->with(['assignedTo', 'related'])
            ->where('status', Task::STATUS_OPEN)
            ->unarchived()
            ->when(
                $frequency === self::FREQUENCY_DAILY,
                fn (Builder $query) => $this->dailyScope($query, $now),
            )
            ->when(
                $frequency === self::FREQUENCY_WEEKLY,
                fn (Builder $query) => $this->weeklyScope($query, $now),
            )
            ->when(
                ! in_array($frequency, [
                    self::FREQUENCY_DAILY,
                    self::FREQUENCY_WEEKLY,
                ], true),
                fn () => throw new InvalidArgumentException("Unsupported task digest frequency [{$frequency}]."),
            )
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->get();
    }

    private function dailyScope(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where(function (Builder $query) use ($now) {
            $query
                ->whereNull('due_at')
                ->orWhere('due_at', '<=', $now->copy()->endOfDay());
        });
    }

    private function weeklyScope(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where('due_at', '<=', $now->copy()->addDays(7)->endOfDay());
    }

    private function recipientKey(InternalNotificationRecipient $recipient): string
    {
        return $this->modelKey($recipient->source);
    }

    private function modelKey(Model $model): string
    {
        return $model::class.':'.$model->getKey();
    }
}