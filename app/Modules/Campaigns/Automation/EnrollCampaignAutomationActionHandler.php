<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Data\Automation\EnrollCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use InvalidArgumentException;
use Throwable;

class EnrollCampaignAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContactInCampaign,
    ) {}

    public function key(): string
    {
        return 'campaigns.enroll_contact';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = EnrollCampaignAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_enroll_campaign_automation_definition',
                output: ['enroll_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed('enroll_campaign_contact_not_found', output: [
                'enroll_campaign_definition' => $definition->toMetaPayload(),
            ]);
        }

        $campaign = Campaign::query()
            ->active()
            ->where('key', $definition->campaignKey)
            ->first();

        if (! $campaign instanceof Campaign) {
            return AutomationActionResult::skipped('campaign_not_active_or_missing', output: [
                'campaign_key' => $definition->campaignKey,
                'enroll_campaign_definition' => $definition->toMetaPayload(),
            ]);
        }

        $existingEnrollment = CampaignEnrollment::query()
            ->where('contact_id', $contact->getKey())
            ->where('campaign_key', $definition->campaignKey)
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->first();

        if ($existingEnrollment instanceof CampaignEnrollment) {
            return $this->alreadyEnrolledResult($existingEnrollment, $definition);
        }

        try {
            $enrollment = $this->enrollContactInCampaign->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->source ?? $contact,
                payload: $this->withRuntimeContext($definition->payload, $context),
                meta: array_replace_recursive(
                    ['source' => 'automation'],
                    $context->meta,
                    $definition->meta,
                ),
                startContext: $definition->startContext === null
                    ? null
                    : array_replace_recursive(
                        $context->runtimeContext,
                        $definition->startContext,
                    ),
                exitConditions: $definition->exitConditions,
            );
        } catch (InvalidArgumentException $exception) {
            return AutomationActionResult::skipped(
                reason: 'campaign_enrollment_not_schedulable',
                output: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed(
                reason: 'enroll_campaign_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        return AutomationActionResult::completed(
            reason: 'campaign_enrolled',
            artifacts: [$enrollment],
            correlationKey: 'campaign_enrollment.id',
            correlationType: 'campaign_enrollment',
            correlation: [
                'campaign_enrollment_id' => $enrollment->getKey(),
                'campaign_key' => $enrollment->campaign_key,
            ],
            output: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'enroll_campaign_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function alreadyEnrolledResult(
        CampaignEnrollment $enrollment,
        EnrollCampaignAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'campaign_enrollment' => $this->enrollmentMeta($enrollment),
            'enroll_campaign_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onAlreadyEnrolled) {
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_already_enrolled',
                artifacts: [$enrollment],
                output: $output,
            ),
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_FAILED => AutomationActionResult::failed(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withRuntimeContext(array $payload, AutomationActionContext $context): array
    {
        if ($context->runtimeContext === []) {
            return $payload;
        }

        return array_replace_recursive($payload, [
            'runtime_context' => $context->runtimeContext,
        ]);
    }

    /** @return array<string, mixed> */
    private function enrollmentMeta(CampaignEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'status' => $enrollment->status,
            'current_step' => $enrollment->current_step,
            'current_campaign_step_id' => $enrollment->current_campaign_step_id,
            'last_scheduled_message_id' => $enrollment->last_scheduled_message_id,
            'started_at' => $enrollment->started_at?->toISOString(),
            'exited_at' => $enrollment->exited_at?->toISOString(),
            'exit_reason' => $enrollment->exit_reason,
        ];
    }
}
