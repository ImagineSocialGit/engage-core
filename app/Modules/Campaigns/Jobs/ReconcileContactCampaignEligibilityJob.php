<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Services\CampaignEligibilityDependencyResolver;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Core\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileContactCampaignEligibilityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param array<int, string> $criterionKeys
     */
    public function __construct(
        public readonly int $contactId,
        public readonly array $criterionKeys,
        public readonly string $occurredAt,
    ) {
        $this->onQueue('campaigns');
        $this->afterCommit();
    }

    public function handle(
        CampaignEligibilityDependencyResolver $dependencies,
        CampaignEligibilityReevaluationGuard $guard,
        ApplyAutomaticCampaignEligibilityAction $applyEligibility,
    ): void {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact || ! $guard->mayEvaluate($contact)) {
            return;
        }

        foreach ($dependencies->forCriterionKeys($this->criterionKeys) as $campaign) {
            $applyEligibility->handle(
                campaign: $campaign,
                contact: $contact,
                at: $this->occurredAt,
            );
        }
    }
}