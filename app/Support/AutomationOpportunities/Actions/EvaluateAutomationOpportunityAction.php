<?php

namespace App\Support\AutomationOpportunities\Actions;

use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EvaluateAutomationOpportunityAction
{
    public const DEFAULT_MINIMUM_OCCURRENCES = 3;
    public const DEFAULT_MINIMUM_DISTINCT_SUBJECTS = 3;
    public const DEFAULT_WINDOW_DAYS = 30;

    public function handle(
        AutomationBehaviorOccurrence $occurrence,
        int $minimumOccurrences = self::DEFAULT_MINIMUM_OCCURRENCES,
        int $minimumDistinctSubjects = self::DEFAULT_MINIMUM_DISTINCT_SUBJECTS,
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ): AutomationOpportunity {
        $this->validateThresholds(
            minimumOccurrences: $minimumOccurrences,
            minimumDistinctSubjects: $minimumDistinctSubjects,
            windowDays: $windowDays,
        );

        return DB::transaction(function () use (
            $occurrence,
            $minimumOccurrences,
            $minimumDistinctSubjects,
            $windowDays,
        ): AutomationOpportunity {
            $opportunity = AutomationOpportunity::query()
                ->where('action_key', $occurrence->action_key)
                ->where('fingerprint', $occurrence->fingerprint)
                ->lockForUpdate()
                ->first();

            if (! $opportunity) {
                $opportunity = AutomationOpportunity::query()->create([
                    'action_key' => $occurrence->action_key,
                    'fingerprint' => $occurrence->fingerprint,
                    'capability_key' => $occurrence->capability_key,
                    'status' => AutomationOpportunity::STATUS_OBSERVING,
                    'occurrence_count' => 0,
                    'distinct_subject_count' => 0,
                    'distinct_actor_count' => 0,
                    'context' => $occurrence->context,
                    'meta' => [
                        'eligibility' => [
                            'minimum_occurrences' => $minimumOccurrences,
                            'minimum_distinct_subjects' => $minimumDistinctSubjects,
                            'window_days' => $windowDays,
                        ],
                    ],
                ]);
            }

            $windowStart = CarbonImmutable::instance(
                $occurrence->occurred_at,
            )->subDays($windowDays);

            $occurrences = AutomationBehaviorOccurrence::query()
                ->where('action_key', $occurrence->action_key)
                ->where('fingerprint', $occurrence->fingerprint)
                ->whereBetween('occurred_at', [
                    $windowStart,
                    $occurrence->occurred_at,
                ]);

            $occurrenceCount = (clone $occurrences)->count();
            $distinctSubjectCount = $this->countDistinctMorphs(
                query: clone $occurrences,
                typeColumn: 'subject_type',
                idColumn: 'subject_id',
            );
            $distinctActorCount = $this->countDistinctMorphs(
                query: clone $occurrences,
                typeColumn: 'actor_type',
                idColumn: 'actor_id',
            );

            $firstOccurredAt = (clone $occurrences)->min('occurred_at');
            $lastOccurredAt = (clone $occurrences)->max('occurred_at');

            $isEligible = $occurrenceCount >= $minimumOccurrences
                && $distinctSubjectCount >= $minimumDistinctSubjects;

            $attributes = [
                'capability_key' => $occurrence->capability_key
                    ?? $opportunity->capability_key,
                'occurrence_count' => $occurrenceCount,
                'distinct_subject_count' => $distinctSubjectCount,
                'distinct_actor_count' => $distinctActorCount,
                'first_occurred_at' => $firstOccurredAt,
                'last_occurred_at' => $lastOccurredAt,
                'context' => $occurrence->context !== []
                    ? $occurrence->context
                    : $opportunity->context,
                'meta' => array_replace_recursive(
                    $opportunity->meta ?? [],
                    [
                        'eligibility' => [
                            'minimum_occurrences' => $minimumOccurrences,
                            'minimum_distinct_subjects' => $minimumDistinctSubjects,
                            'window_days' => $windowDays,
                        ],
                    ],
                ),
            ];

            if ($isEligible && $opportunity->status === AutomationOpportunity::STATUS_OBSERVING) {
                $attributes['status'] = AutomationOpportunity::STATUS_ELIGIBLE;
                $attributes['eligible_at'] = $opportunity->eligible_at
                    ?? $occurrence->occurred_at;
            }

            $opportunity->forceFill($attributes)->save();

            return $opportunity->refresh();
        });
    }

    private function countDistinctMorphs(
        Builder $query,
        string $typeColumn,
        string $idColumn,
    ): int {
        $result = $query
            ->whereNotNull($typeColumn)
            ->whereNotNull($idColumn)
            ->selectRaw("COUNT(DISTINCT {$typeColumn}, {$idColumn}) as aggregate")
            ->value('aggregate');

        return (int) ($result ?? 0);
    }

    private function validateThresholds(
        int $minimumOccurrences,
        int $minimumDistinctSubjects,
        int $windowDays,
    ): void {
        if ($minimumOccurrences < 1) {
            throw new InvalidArgumentException('Minimum automation opportunity occurrences must be at least 1.');
        }

        if ($minimumDistinctSubjects < 0) {
            throw new InvalidArgumentException('Minimum distinct automation opportunity subjects cannot be negative.');
        }

        if ($windowDays < 1) {
            throw new InvalidArgumentException('Automation opportunity observation window must be at least 1 day.');
        }
    }
}
