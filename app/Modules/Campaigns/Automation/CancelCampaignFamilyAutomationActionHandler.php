<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\CancelCampaignFamilyEnrollmentsAction;
use App\Modules\Campaigns\Data\Automation\CancelCampaignFamilyAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class CancelCampaignFamilyAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly CancelCampaignFamilyEnrollmentsAction $cancelCampaignFamilyEnrollments,
    ) {}

    public function key(): string
    {
        return 'campaigns.cancel_family_enrollments';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = CancelCampaignFamilyAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_cancel_campaign_family_automation_definition',
                output: ['cancel_campaign_family_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'cancel_campaign_family_contact_not_found',
                output: ['cancel_campaign_family_definition' => $definition->toMetaPayload()],
            );
        }

        try {
            $enrollments = $this->cancelCampaignFamilyEnrollments->handle(
                contact: $contact,
                familyKey: $definition->familyKey,
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
                reason: 'cancel_campaign_family_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'cancel_campaign_family_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if ($enrollments->isEmpty()) {
            return $this->notEnrolledResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'campaign_family_cancelled',
            artifacts: $enrollments->all(),
            output: [
                'campaign_enrollments' => $this->enrollmentMeta($enrollments),
                'cancel_campaign_family_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function notEnrolledResult(
        CancelCampaignFamilyAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'cancel_campaign_family_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNotEnrolled) {
            CancelCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
            CancelCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
            CancelCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_FAILED => AutomationActionResult::failed(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
        };
    }

    /**
     * @param Collection<int, CampaignEnrollment> $enrollments
     * @return array<int, array<string, mixed>>
     */
    private function enrollmentMeta(Collection $enrollments): array
    {
        return $enrollments
            ->map(function (CampaignEnrollment $enrollment): array {
                $enrollment->loadMissing(['campaign', 'messageChainEnrollment']);

                return [
                    'id' => $enrollment->getKey(),
                    'campaign_key' => $enrollment->campaign_key,
                    'family_key' => $enrollment->campaign?->family_key,
                    'message_chain_enrollment_id' => $enrollment->message_chain_enrollment_id,
                    'status' => $enrollment->messageChainEnrollment?->status,
                ];
            })
            ->values()
            ->all();
    }
}