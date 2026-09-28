<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CampaignAllocationCandidateSelector
{
    private const SCAN_CHUNK = 500;

    public function __construct(
        private readonly CampaignEligibilityEvaluator $eligibility,
    ) {}

    /**
     * @param Collection<int, MessageChainStep> $orderedSteps
     * @return Collection<int, CampaignAllocationEnrollment>
     */
    public function select(
        Campaign $campaign,
        int $runId,
        MessageChainStep $step,
        Collection $orderedSteps,
        int $cooldownDays,
        int $limit,
        array $excludeContactIds = [],
        Carbon|string|null $at = null,
    ): Collection {
        if ($limit < 1) {
            return collect();
        }

        $at = $at ? Carbon::parse($at)->utc() : now()->utc();
        $cutoff = $at->copy()->subDays(max(0, $cooldownDays));
        $eligibleFloorKeys = $this->eligibleFloorKeys($orderedSteps, $step);
        $selected = collect();
        $offset = 0;

        while ($selected->count() < $limit) {
            $rows = $this->baseQuery(
                campaign: $campaign,
                runId: $runId,
                step: $step,
                eligibleFloorKeys: $eligibleFloorKeys,
                cooldownDays: $cooldownDays,
                cutoff: $cutoff,
                excludeContactIds: $excludeContactIds,
            )
                ->offset($offset)
                ->limit(self::SCAN_CHUNK)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $enrollment) {
                if (! $enrollment instanceof CampaignAllocationEnrollment
                    || ! $enrollment->contact instanceof Contact
                ) {
                    continue;
                }

                if ($campaign->hasEligibilityCriteria()
                    && ! $this->eligibility->eligible(
                        campaign: $campaign,
                        contact: $enrollment->contact,
                    )
                ) {
                    continue;
                }

                $selected->push($enrollment);

                if ($selected->count() >= $limit) {
                    break;
                }
            }

            $offset += $rows->count();

            if ($rows->count() < self::SCAN_CHUNK) {
                break;
            }
        }

        return $selected->values();
    }

    /**
     * @param array<int, string> $eligibleFloorKeys
     */
    private function baseQuery(
        Campaign $campaign,
        int $runId,
        MessageChainStep $step,
        array $eligibleFloorKeys,
        int $cooldownDays,
        Carbon $cutoff,
        array $excludeContactIds,
    ): Builder {
        $campaignId = (int) $campaign->getKey();
        $stepKey = (string) $step->key;
        $enrollmentTable = (new CampaignAllocationEnrollment())->getTable();

        $lastAssignments = CampaignAllocationAssignment::query()
            ->selectRaw('contact_id, MAX(assigned_at) AS last_assignment_at')
            ->where('campaign_id', $campaignId)
            ->groupBy('contact_id');

        $lastReceipts = CampaignPriorMessageReceipt::query()
            ->selectRaw('contact_id, MAX(received_at) AS last_receipt_at')
            ->where('campaign_id', $campaignId)
            ->whereNotNull('received_at')
            ->groupBy('contact_id');

        $query = CampaignAllocationEnrollment::query()
            ->select($enrollmentTable.'.*')
            ->selectRaw(
                'CASE
                    WHEN allocation_history.last_assignment_at IS NULL
                        THEN prior_receipt_history.last_receipt_at
                    WHEN prior_receipt_history.last_receipt_at IS NULL
                        THEN allocation_history.last_assignment_at
                    WHEN allocation_history.last_assignment_at >= prior_receipt_history.last_receipt_at
                        THEN allocation_history.last_assignment_at
                    ELSE prior_receipt_history.last_receipt_at
                END AS last_allocation_activity_at',
            )
            ->with('contact')
            ->leftJoinSub(
                $lastAssignments,
                'allocation_history',
                fn ($join) => $join->on(
                    'allocation_history.contact_id',
                    '=',
                    $enrollmentTable.'.contact_id',
                ),
            )
            ->leftJoinSub(
                $lastReceipts,
                'prior_receipt_history',
                fn ($join) => $join->on(
                    'prior_receipt_history.contact_id',
                    '=',
                    $enrollmentTable.'.contact_id',
                ),
            )
            ->where($enrollmentTable.'.campaign_id', $campaignId)
            ->where(
                $enrollmentTable.'.status',
                CampaignAllocationEnrollment::STATUS_ACTIVE,
            )
            ->when(
                $excludeContactIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn(
                    $enrollmentTable.'.contact_id',
                    array_values(array_unique(array_map('intval', $excludeContactIds))),
                ),
            )
            ->where(function (Builder $query) use (
                $enrollmentTable,
                $eligibleFloorKeys,
            ): void {
                $query->whereNull($enrollmentTable.'.start_message_step_key');

                if ($eligibleFloorKeys !== []) {
                    $query->orWhereIn(
                        $enrollmentTable.'.start_message_step_key',
                        $eligibleFloorKeys,
                    );
                }
            })
            ->whereNotExists(function ($query) use (
                $campaignId,
                $stepKey,
                $enrollmentTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_allocation_assignments as assigned_step')
                    ->where('assigned_step.campaign_id', $campaignId)
                    ->whereColumn(
                        'assigned_step.contact_id',
                        $enrollmentTable.'.contact_id',
                    )
                    ->where('assigned_step.message_step_key', $stepKey);
            })
            ->whereNotExists(function ($query) use (
                $campaignId,
                $stepKey,
                $enrollmentTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_prior_message_receipts as prior_step_receipt')
                    ->where('prior_step_receipt.campaign_id', $campaignId)
                    ->whereColumn(
                        'prior_step_receipt.contact_id',
                        $enrollmentTable.'.contact_id',
                    )
                    ->where('prior_step_receipt.message_step_key', $stepKey);
            })
            ->whereNotExists(function ($query) use (
                $campaignId,
                $stepKey,
                $enrollmentTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_allocation_message_exclusions as allocation_exclusion')
                    ->where('allocation_exclusion.campaign_id', $campaignId)
                    ->whereColumn(
                        'allocation_exclusion.contact_id',
                        $enrollmentTable.'.contact_id',
                    )
                    ->where('allocation_exclusion.message_step_key', $stepKey);
            })
            ->whereNotExists(function ($query) use (
                $runId,
                $enrollmentTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_allocation_assignments as current_run_assignment')
                    ->where(
                        'current_run_assignment.campaign_allocation_run_id',
                        $runId,
                    )
                    ->whereColumn(
                        'current_run_assignment.contact_id',
                        $enrollmentTable.'.contact_id',
                    );
            })
            ->whereNotExists(function ($query) use (
                $campaignId,
                $enrollmentTable,
            ): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_allocation_assignments as active_assignment')
                    ->join(
                        'scheduled_messages as active_scheduled_message',
                        'active_scheduled_message.id',
                        '=',
                        'active_assignment.scheduled_message_id',
                    )
                    ->where('active_assignment.campaign_id', $campaignId)
                    ->whereColumn(
                        'active_assignment.contact_id',
                        $enrollmentTable.'.contact_id',
                    )
                    ->whereIn('active_scheduled_message.status', [
                        ScheduledMessage::STATUS_PENDING,
                        ScheduledMessage::STATUS_SENDING,
                    ]);
            })
            ->whereNotExists(function ($query) use ($enrollmentTable): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_allocation_enrollments as newer_active_enrollment')
                    ->whereColumn(
                        'newer_active_enrollment.campaign_id',
                        $enrollmentTable.'.campaign_id',
                    )
                    ->whereColumn(
                        'newer_active_enrollment.contact_id',
                        $enrollmentTable.'.contact_id',
                    )
                    ->where(
                        'newer_active_enrollment.status',
                        CampaignAllocationEnrollment::STATUS_ACTIVE,
                    )
                    ->where(function ($query) use ($enrollmentTable): void {
                        $query
                            ->whereColumn(
                                'newer_active_enrollment.started_at',
                                '>',
                                $enrollmentTable.'.started_at',
                            )
                            ->orWhere(function ($query) use ($enrollmentTable): void {
                                $query
                                    ->whereColumn(
                                        'newer_active_enrollment.started_at',
                                        '=',
                                        $enrollmentTable.'.started_at',
                                    )
                                    ->whereColumn(
                                        'newer_active_enrollment.id',
                                        '>',
                                        $enrollmentTable.'.id',
                                    );
                            });
                    });
            });

        if ($cooldownDays > 0) {
            $query
                ->where(function (Builder $query) use ($cutoff): void {
                    $query
                        ->whereNull('allocation_history.last_assignment_at')
                        ->orWhere(
                            'allocation_history.last_assignment_at',
                            '<=',
                            $cutoff,
                        );
                })
                ->where(function (Builder $query) use ($cutoff): void {
                    $query
                        ->whereNull('prior_receipt_history.last_receipt_at')
                        ->orWhere(
                            'prior_receipt_history.last_receipt_at',
                            '<=',
                            $cutoff,
                        );
                });
        }

        return $query
            ->orderByRaw(
                'CASE WHEN allocation_history.last_assignment_at IS NULL
                    AND prior_receipt_history.last_receipt_at IS NULL
                    THEN 0 ELSE 1 END',
            )
            ->orderBy('last_allocation_activity_at')
            ->orderBy($enrollmentTable.'.started_at')
            ->orderBy($enrollmentTable.'.id');
    }

    /**
     * @param Collection<int, MessageChainStep> $orderedSteps
     * @return array<int, string>
     */
    private function eligibleFloorKeys(
        Collection $orderedSteps,
        MessageChainStep $targetStep,
    ): array {
        $keys = [];

        foreach ($orderedSteps as $step) {
            if (! $step instanceof MessageChainStep || ! $step->is_active) {
                continue;
            }

            $keys[] = (string) $step->key;

            if ((int) $step->getKey() === (int) $targetStep->getKey()) {
                break;
            }
        }

        return $keys;
    }
}