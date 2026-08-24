<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Services\CampaignEligibilityDependencyResolver;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventConsumer;

final class ReconcileCampaignEligibilityFromAutomationEvent
{
    private const CONSUMER = 'campaigns.eligibility_reconciliation';
    private const WORKFLOW_STATUS_CHANGED = 'workflow.contact_status_changed';

    public function __construct(
        private readonly AutomationEventConsumer $automationEventConsumer,
        private readonly CampaignEligibilityDependencyResolver $dependencies,
        private readonly CampaignEligibilityReevaluationGuard $guard,
        private readonly ApplyAutomaticCampaignEligibilityAction $applyEligibility,
    ) {}

    public function handle(AutomationEventRecorded $recorded): void
    {
        $event = $recorded->event;

        if ($event->eventKey !== self::WORKFLOW_STATUS_CHANGED || $event->contactId === null) {
            return;
        }

        if (! $event->hasDurableIdentity()) {
            $this->reconcile($event);

            return;
        }

        $this->automationEventConsumer->consume(
            eventId: (string) $event->eventId,
            consumer: self::CONSUMER,
            effect: fn (): mixed => $this->reconcile($event),
        );
    }

    private function reconcile(AutomationEventData $event): void
    {
        $contact = Contact::query()->find($event->contactId);

        if (! $contact instanceof Contact || ! $this->guard->mayEvaluate($contact)) {
            return;
        }

        foreach ($this->dependencies->forCriterionKeys(['status']) as $campaign) {
            $this->applyEligibility->handle(
                campaign: $campaign,
                contact: $contact,
                at: $event->occurredAt,
            );
        }
    }
}