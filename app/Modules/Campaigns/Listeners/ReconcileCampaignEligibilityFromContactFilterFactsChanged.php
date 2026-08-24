<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Jobs\ReconcileContactCampaignEligibilityJob;
use App\Modules\Campaigns\Services\CampaignEligibilityDependencyResolver;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Modules\Core\Models\Contact;

final class ReconcileCampaignEligibilityFromContactFilterFactsChanged
{
    public function __construct(
        private readonly CampaignEligibilityDependencyResolver $dependencies,
        private readonly CampaignEligibilityReevaluationGuard $guard,
    ) {}

    public function handle(ContactFilterFactsChanged $event): void
    {
        $contact = Contact::query()->find($event->contactId);

        if (! $contact instanceof Contact || ! $this->guard->mayEvaluate($contact)) {
            return;
        }

        if ($this->dependencies->forCriterionKeys($event->criterionKeys)->isEmpty()) {
            return;
        }

        ReconcileContactCampaignEligibilityJob::dispatch(
            contactId: (int) $contact->getKey(),
            criterionKeys: $event->criterionKeys,
            occurredAt: $event->occurredAt->toIso8601String(),
        );
    }
}