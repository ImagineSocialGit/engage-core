<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Data\Automation\CancelCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
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
        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'channel' => $enrollment->channel,
            'purpose' => $enrollment->purpose,
            'scope' => $enrollment->scope,
            'status' => $enrollment->status,
            'current_step' => $enrollment->current_step,
            'current_campaign_step_id' => $enrollment->current_campaign_step_id,
            'last_scheduled_message_id' => $enrollment->last_scheduled_message_id,
            'started_at' => $enrollment->started_at?->toISOString(),
            'cancelled_at' => $enrollment->cancelled_at?->toISOString(),
            'exited_at' => $enrollment->exited_at?->toISOString(),
            'exit_reason' => $enrollment->exit_reason,
        ];
    }
}
