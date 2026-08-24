<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class ReconcileCampaignEligibilityAction
{
    private const DEFAULT_CHUNK_SIZE = 250;
    private const MAX_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly ApplyAutomaticCampaignEligibilityAction $applyEligibility,
        private readonly CampaignEligibilityReevaluationGuard $guard,
    ) {}

    /**
     * @return array{
     *     campaign_id: int,
     *     campaign_key: string,
     *     contacts_processed: int,
     *     contacts_skipped: int,
     *     evaluated: int,
     *     actions: array<string, int>
     * }
     */
    public function handle(
        Campaign $campaign,
        Carbon|string|null $at = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): array {
        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'Campaign eligibility reconciliation chunk size must be between 1 and %d.',
                self::MAX_CHUNK_SIZE,
            ));
        }

        $summary = [
            'campaign_id' => (int) $campaign->getKey(),
            'campaign_key' => (string) $campaign->key,
            'contacts_processed' => 0,
            'contacts_skipped' => 0,
            'evaluated' => 0,
            'actions' => [],
        ];

        Contact::query()
            ->orderBy('id')
            ->chunkById(
                $chunkSize,
                function ($contacts) use ($campaign, $at, &$summary): void {
                    foreach ($contacts as $contact) {
                        if (! $this->guard->mayEvaluate($contact)) {
                            $summary['contacts_skipped']++;

                            continue;
                        }

                        $result = $this->applyEligibility->handle(
                            campaign: $campaign,
                            contact: $contact,
                            at: $at,
                        );

                        $summary['contacts_processed']++;

                        if ($result->evaluation !== null) {
                            $summary['evaluated']++;
                        }

                        $summary['actions'][$result->action] =
                            ($summary['actions'][$result->action] ?? 0) + 1;
                    }
                },
                'id',
            );

        ksort($summary['actions']);

        return $summary;
    }
}