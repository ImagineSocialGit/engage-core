<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\PauseCampaignFamilyEnrollmentsAction;
use App\Modules\Campaigns\Data\Automation\PauseCampaignFamilyAutomationDefinition;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class PauseCampaignFamilyAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly PauseCampaignFamilyEnrollmentsAction $pauseCampaignFamilyEnrollments,
    ) {}

    public function key(): string
    {
        return 'campaigns.pause_family_enrollments';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = PauseCampaignFamilyAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_pause_campaign_family_automation_definition',
                output: ['pause_campaign_family_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'pause_campaign_family_contact_not_found',
                output: ['pause_campaign_family_definition' => $definition->toMetaPayload()],
            );
        }

        try {
            $enrollments = $this->pauseCampaignFamilyEnrollments->handle(
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
                reason: 'pause_campaign_family_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'pause_campaign_family_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if ($enrollments->isEmpty()) {
            return $this->notEnrolledResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'campaign_family_paused',
            artifacts: $enrollments->all(),
            output: [
                'campaign_enrollments' => $this->enrollmentMeta($enrollments),
                'pause_campaign_family_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function notEnrolledResult(
        PauseCampaignFamilyAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'pause_campaign_family_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNotEnrolled) {
            PauseCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
            PauseCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_family_not_enrolled',
                output: $output,
            ),
            PauseCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_FAILED => AutomationActionResult::failed(
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