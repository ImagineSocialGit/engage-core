<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Services\CampaignEligibilityDependencyResolver;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Campaigns\Services\CampaignEligibilityReconciliationPlanner;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventConsumer;

final class ReconcileCampaignEligibilityFromAutomationEvent
{
    private const CONSUMER = 'campaigns.eligibility_reconciliation';

    public function __construct(
        private readonly AutomationEventConsumer $automationEventConsumer,
        private readonly CampaignEligibilityDependencyResolver $dependencies,
        private readonly CampaignEligibilityReevaluationGuard $guard,
        private readonly CampaignEligibilityReconciliationPlanner $planner,
        private readonly ApplyAutomaticCampaignEligibilityAction $applyEligibility,
    ) {}

    public function handle(AutomationEventRecorded $recorded): void
    {
        $event = $recorded->event;
        $criterionKeys = $this->criterionKeys($event);

        if ($event->contactId === null || $criterionKeys === []) {
            return;
        }

        if (! $event->hasDurableIdentity()) {
            $this->reconcile($event, $criterionKeys);

            return;
        }

        $this->automationEventConsumer->consume(
            eventId: (string) $event->eventId,
            consumer: self::CONSUMER,
            effect: fn (): mixed => $this->reconcile($event, $criterionKeys),
        );
    }

    /**
     * Campaigns consumes neutral durable event keys. It does not import
     * Workflow or Webinars classes merely to learn that one of their durable
     * Contact facts may have changed.
     *
     * @return array<int, string>
     */
    private function criterionKeys(AutomationEventData $event): array
    {
        return match ($event->eventKey) {
            'workflow.contact_status_changed' => ['status'],
            'webinar.attended',
            'webinar.missed' => ['webinar_outcome'],
            default => [],
        };
    }

    /**
     * @param array<int, string> $criterionKeys
     */
    private function reconcile(
        AutomationEventData $event,
        array $criterionKeys,
    ): void {
        $contact = Contact::query()->find($event->contactId);

        if (! $contact instanceof Contact || ! $this->guard->mayEvaluate($contact)) {
            return;
        }

        $campaigns = $this->planner->orderForContact(
            contact: $contact,
            campaigns: $this->dependencies->forCriterionKeys($criterionKeys),
        );

        foreach ($campaigns as $campaign) {
            $this->applyEligibility->handle(
                campaign: $campaign,
                contact: $contact,
                at: $event->occurredAt,
            );
        }
    }
}