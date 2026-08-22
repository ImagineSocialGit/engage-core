<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\ResumeCampaignEnrollmentAction;
use App\Modules\Campaigns\Data\Automation\ResumeCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use InvalidArgumentException;
use Throwable;

class ResumeCampaignAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly ResumeCampaignEnrollmentAction $resumeCampaignEnrollment,
    ) {}

    public function key(): string
    {
        return 'campaigns.resume_enrollment';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = ResumeCampaignAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_resume_campaign_automation_definition',
                output: ['resume_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'resume_campaign_contact_not_found',
                output: ['resume_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        try {
            $enrollment = $this->resumeCampaignEnrollment->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->source ?? $contact,
                reason: $definition->reason,
                meta: array_replace_recursive(
                    ['source' => 'automation'],
                    $context->meta,
                    $definition->meta,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return AutomationActionResult::skipped(
                reason: 'campaign_resume_unavailable',
                output: [
                    'error' => $exception->getMessage(),
                    'resume_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed(
                reason: 'resume_campaign_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'resume_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if (! $enrollment instanceof CampaignEnrollment) {
            return $this->notEnrolledResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'campaign_resumed',
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
                'resume_campaign_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function notEnrolledResult(
        ResumeCampaignAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'resume_campaign_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNotEnrolled) {
            ResumeCampaignAutomationDefinition::ON_NOT_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            ResumeCampaignAutomationDefinition::ON_NOT_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_not_enrolled',
                output: $output,
            ),
            ResumeCampaignAutomationDefinition::ON_NOT_ENROLLED_FAILED => AutomationActionResult::failed(
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
            'resumed_at' => $chainEnrollment?->resumed_at?->toISOString(),
        ];
    }
}