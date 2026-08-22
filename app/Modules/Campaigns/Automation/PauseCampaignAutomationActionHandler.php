<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\PauseCampaignEnrollmentAction;
use App\Modules\Campaigns\Data\Automation\PauseCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Throwable;

class PauseCampaignAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly PauseCampaignEnrollmentAction $pauseCampaignEnrollment,
    ) {}

    public function key(): string
    {
        return 'campaigns.pause_enrollment';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = PauseCampaignAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_pause_campaign_automation_definition',
                output: ['pause_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'pause_campaign_contact_not_found',
                output: ['pause_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        try {
            $enrollment = $this->pauseCampaignEnrollment->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->source ?? $contact,
                reason: $definition->reason,
                skipPendingMessages: $definition->skipPendingMessages,
                meta: array_replace_recursive(
                    ['source' => 'automation'],
                    $context->meta,
                    $definition->meta,
                ),
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed(
                reason: 'pause_campaign_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'pause_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if (! $enrollment instanceof CampaignEnrollment) {
            return $this->notEnrolledResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'campaign_paused',
            artifacts: [$enrollment],
            correlationKey: 'campaign_enrollment.id',
            correlationType: 'campaign_enrollment',
            correlation: [
                'campaign_enrollment_id' => $enrollment->getKey(),
                'campaign_key' => $enrollment->campaign_key,
                'message_chain_enrollment_id' => $enrollment->message_chain_enrollment_id,
            ],
            output: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'pause_campaign_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function notEnrolledResult(
        PauseCampaignAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'pause_campaign_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNotEnrolled) {
            PauseCampaignAutomationDefinition::ON_NOT_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            PauseCampaignAutomationDefinition::ON_NOT_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            PauseCampaignAutomationDefinition::ON_NOT_ENROLLED_FAILED => AutomationActionResult::failed(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
        };
    }

    /** @return array<string, mixed> */
    private function enrollmentMeta(CampaignEnrollment $enrollment): array
    {
        $enrollment->loadMissing('messageChainEnrollment');
        $chainEnrollment = $enrollment->messageChainEnrollment;

        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'message_chain_enrollment_id' => $chainEnrollment?->getKey(),
            'status' => $chainEnrollment?->status,
            'next_action_at' => $chainEnrollment?->next_action_at?->toISOString(),
            'paused_at' => $chainEnrollment?->paused_at?->toISOString(),
        ];
    }
}