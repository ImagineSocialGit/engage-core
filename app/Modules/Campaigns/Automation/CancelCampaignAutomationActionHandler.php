<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Data\Automation\CancelCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Throwable;

class CancelCampaignAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly CancelCampaignEnrollmentAction $cancelCampaignEnrollment,
    ) {}

    public function key(): string
    {
        return 'campaigns.cancel_enrollment';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = CancelCampaignAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_cancel_campaign_automation_definition',
                output: ['cancel_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed('cancel_campaign_contact_not_found', output: [
                'cancel_campaign_definition' => $definition->toMetaPayload(),
            ]);
        }

        try {
            $enrollment = $this->cancelCampaignEnrollment->handle(
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
                reason: 'cancel_campaign_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'cancel_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if (! $enrollment instanceof CampaignEnrollment) {
            return $this->notEnrolledResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'campaign_cancelled',
            artifacts: [$enrollment],
            output: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'cancel_campaign_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function notEnrolledResult(
        CancelCampaignAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'cancel_campaign_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNotEnrolled) {
            CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_FAILED => AutomationActionResult::failed(
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
        $enrollment->loadMissing(['campaign', 'messageChainEnrollment']);
        $campaign = $enrollment->campaign;
        $chainEnrollment = $enrollment->messageChainEnrollment;

        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'channel' => $campaign?->channel,
            'purpose' => $campaign?->purpose,
            'scope' => $campaign?->scope,
            'message_chain_enrollment_id' => $chainEnrollment?->getKey(),
            'status' => $chainEnrollment?->status,
            'message_chain_status' => $chainEnrollment?->status,
            'message_chain_version_id' => $chainEnrollment?->message_chain_version_id,
            'current_message_chain_step_id' => $chainEnrollment?->current_message_chain_step_id,
            'next_action_at' => $chainEnrollment?->next_action_at?->toISOString(),
            'started_at' => $chainEnrollment?->started_at?->toISOString()
                ?? $enrollment->started_at?->toISOString(),
            'cancelled_at' => $chainEnrollment?->cancelled_at?->toISOString(),
            'exited_at' => $chainEnrollment?->exited_at?->toISOString(),
            'completed_at' => $chainEnrollment?->completed_at?->toISOString(),
            'exit_reason' => $chainEnrollment?->exit_reason_code,
        ];
    }
}