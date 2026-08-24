<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class ReconcileAutomaticCampaignEligibilityAction
{
    private const DEFAULT_CHUNK_SIZE = 250;
    private const MAX_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly ApplyAutomaticCampaignEligibilityAction $applyEligibility,
        private readonly CampaignEligibilityReevaluationGuard $guard,
    ) {}

    /**
     * @return array{
     *     campaigns_processed: int,
     *     contacts_processed: int,
     *     contacts_skipped: int,
     *     evaluated: int,
     *     actions: array<string, int>
     * }
     */
    public function handle(
        Carbon|string|null $at = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): array {
        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'Automatic Campaign eligibility reconciliation chunk size must be between 1 and %d.',
                self::MAX_CHUNK_SIZE,
            ));
        }

        $campaigns = Campaign::query()
            ->active()
            ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
            ->orderBy('id')
            ->get();

        $summary = [
            'campaigns_processed' => $campaigns->count(),
            'contacts_processed' => 0,
            'contacts_skipped' => 0,
            'evaluated' => 0,
            'actions' => [],
        ];

        if ($campaigns->isEmpty()) {
            return $summary;
        }

        Contact::query()
            ->orderBy('id')
            ->chunkById(
                $chunkSize,
                function ($contacts) use ($campaigns, $at, &$summary): void {
                    foreach ($contacts as $contact) {
                        if (! $this->guard->mayEvaluate($contact)) {
                            $summary['contacts_skipped']++;

                            continue;
                        }

                        $summary['contacts_processed']++;

                        foreach ($campaigns as $campaign) {
                            $result = $this->applyEligibility->handle(
                                campaign: $campaign,
                                contact: $contact,
                                at: $at,
                            );

                            if ($result->evaluation !== null) {
                                $summary['evaluated']++;
                            }

                            $summary['actions'][$result->action] =
                                ($summary['actions'][$result->action] ?? 0) + 1;
                        }
                    }
                },
                'id',
            );

        ksort($summary['actions']);

        return $summary;
    }
}